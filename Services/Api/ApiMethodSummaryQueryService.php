<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Api;

use Piwik\API\DocumentationGenerator;
use Piwik\API\NoDefaultValue;
use Piwik\API\Proxy;
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryQueryRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\Support\Access\RawApiMethodPolicy;
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;
use Piwik\Plugins\McpServer\Support\Search\SearchTermMatcher;
use ReflectionClass;

final class ApiMethodSummaryQueryService implements ApiMethodSummaryQueryServiceInterface
{
    /**
     * @return array<int, ApiMethodSummaryRecord>
     */
    public function getApiMethodSummaries(ApiMethodSummaryQueryRecord $query): array
    {
        return $this->filterRecords($this->loadApiMethodSummaries(), $query);
    }

    public function getApiMethodSummaryBySelector(
        string $accessMode,
        ?string $method = null,
        ?string $module = null,
        ?string $action = null,
    ): ApiMethodSummaryRecord {
        $records = $this->filterRecords(
            $this->loadApiMethodSummaries(),
            ApiMethodSummaryQueryRecord::fromInputs($accessMode),
        );

        $selectedRecord = $this->findApiMethodSummaryRecord($records, $method, $module, $action);
        if ($selectedRecord === null) {
            throw new McpToolCallException('API method not found or unavailable.');
        }

        return $selectedRecord;
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @return array<int, ApiMethodSummaryRecord>
     */
    public function loadApiMethodSummaries(): array
    {
        // Mirrors API docs loading semantics by forcing API class registration through DocumentationGenerator.
        // Proxy metadata remains the source of truth for @hide-style visibility; this service only adds the
        // extra @internal filtering that DocumentationGenerator applies after loading metadata.
        new DocumentationGenerator();

        $proxy = Proxy::getInstance();
        $metadata = $proxy->getMetadata();

        $records = [];
        foreach ($metadata as $className => $classInfo) {
            if (!is_array($classInfo)) {
                continue;
            }

            $module = $proxy->getModuleNameFromClassName((string) $className);
            foreach ($classInfo as $action => $methodInfo) {
                $isDeprecated = $proxy->isDeprecatedMethod((string) $className, (string) $action);
                $shouldInclude = $this->shouldIncludeMethodMetadataEntry(
                    $className,
                    $action,
                    $methodInfo,
                    $isDeprecated,
                );
                if (!$shouldInclude) {
                    continue;
                }
                /** @var array<string, mixed> $methodInfo */

                $parameters = $this->normalizeParameterMetadata($methodInfo['parameters'] ?? null);
                $classification = ApiMethodOperationClassifier::classify(
                    $module . '.' . (string) $action,
                    (string) $action,
                );

                $records[] = new ApiMethodSummaryRecord(
                    module: $module,
                    action: (string) $action,
                    method: $module . '.' . $action,
                    parameters: $parameters,
                    operationCategory: $classification['operationCategory'],
                    classificationConfidence: $classification['classificationConfidence'],
                    classificationReason: $classification['classificationReason'],
                );
            }
        }

        return $records;
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<int, ApiMethodSummaryRecord> $records
     * @return array<int, ApiMethodSummaryRecord>
     */
    public function filterRecords(array $records, ApiMethodSummaryQueryRecord $query): array
    {
        $records = $this->filterByAccessPolicy($records, $query->accessMode);
        $records = $this->filterByModule($records, $query->module);
        $records = $this->filterBySearch($records, $query->search);
        $records = $this->filterByOperationCategory($records, $query->operationCategory);

        return $records;
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<int, ApiMethodSummaryRecord> $records
     */
    public function findApiMethodSummaryRecord(
        array $records,
        ?string $method = null,
        ?string $module = null,
        ?string $action = null,
    ): ?ApiMethodSummaryRecord {
        $normalizedMethod = $this->normalizeSelectorValue($method);
        if ($normalizedMethod !== '') {
            foreach ($records as $record) {
                if ($this->normalizeSelectorValue($record->method) === $normalizedMethod) {
                    return $record;
                }
            }

            return null;
        }

        $normalizedModule = $this->normalizeSelectorValue($module);
        $normalizedAction = $this->normalizeSelectorValue($action);

        foreach ($records as $record) {
            if (
                $this->normalizeSelectorValue($record->module) === $normalizedModule
                && $this->normalizeSelectorValue($record->action) === $normalizedAction
            ) {
                return $record;
            }
        }

        return null;
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @return list<array{
     *     name: string,
     *     type: string|null,
     *     required: bool,
     *     allowsNull: bool,
     *     hasDefault: bool,
     *     defaultValue: mixed,
     * }>
     */
    public function normalizeParameterMetadata(mixed $rawParameters): array
    {
        if (!is_array($rawParameters)) {
            return [];
        }

        $normalized = [];
        foreach ($rawParameters as $name => $parameterInfo) {
            if (!is_string($name) || !is_array($parameterInfo)) {
                continue;
            }

            $hasDefault = array_key_exists('default', $parameterInfo);
            $defaultValue = null;
            if ($hasDefault) {
                $defaultValue = $parameterInfo['default'];
                if ($defaultValue instanceof NoDefaultValue) {
                    $hasDefault = false;
                    $defaultValue = null;
                }
            }

            $allowsNull = $parameterInfo['allowsNull'] ?? false;
            $type = $parameterInfo['type'] ?? null;

            $normalized[] = [
                'name' => $name,
                'type' => is_string($type) ? $type : null,
                'required' => !$hasDefault,
                'allowsNull' => (bool) $allowsNull,
                'hasDefault' => $hasDefault,
                'defaultValue' => $this->normalizeDefaultParameterValue($defaultValue),
            ];
        }

        return $normalized;
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     */
    public function normalizeDefaultParameterValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null || is_array($value)) {
            return $value;
        }

        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR);
            return json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     */
    public function shouldIncludeMethodMetadataEntry(
        mixed $className,
        mixed $action,
        mixed $methodInfo,
        bool $isDeprecated,
    ): bool {
        if (!is_string($action) || $action === '__documentation') {
            return false;
        }

        if ($isDeprecated) {
            return false;
        }

        if (!is_array($methodInfo)) {
            return false;
        }

        if (!is_string($className) || !class_exists($className)) {
            return true;
        }

        $classReflection = new ReflectionClass($className);
        if ($this->hasInternalAnnotation($classReflection->getDocComment())) {
            return false;
        }

        if (!$classReflection->hasMethod($action)) {
            return true;
        }

        return !$this->hasInternalAnnotation($classReflection->getMethod($action)->getDocComment());
    }

    /**
     * @param array<int, ApiMethodSummaryRecord> $records
     * @return array<int, ApiMethodSummaryRecord>
     */
    private function filterByAccessPolicy(array $records, string $accessMode): array
    {
        return array_values(array_filter(
            $records,
            static fn(ApiMethodSummaryRecord $record): bool => RawApiMethodPolicy::allowsMethod(
                $accessMode,
                $record->method,
                $record->action,
                $record->operationCategory,
                $record->classificationConfidence,
            )
        ));
    }

    /**
     * @param array<int, ApiMethodSummaryRecord> $records
     * @return array<int, ApiMethodSummaryRecord>
     */
    private function filterByModule(array $records, string $moduleFilter): array
    {
        if ($moduleFilter === '') {
            return $records;
        }

        return array_values(array_filter(
            $records,
            static fn(ApiMethodSummaryRecord $record): bool => strtolower($record->module) === $moduleFilter
        ));
    }

    /**
     * @param array<int, ApiMethodSummaryRecord> $records
     * @return array<int, ApiMethodSummaryRecord>
     */
    private function filterBySearch(array $records, string $searchTerm): array
    {
        if ($searchTerm === '') {
            return $records;
        }

        return array_values(array_filter(
            $records,
            static fn(ApiMethodSummaryRecord $record): bool => SearchTermMatcher::matches($searchTerm, $record->method)
        ));
    }

    /**
     * @param array<int, ApiMethodSummaryRecord> $records
     * @return array<int, ApiMethodSummaryRecord>
     */
    private function filterByOperationCategory(array $records, string $operationCategory): array
    {
        if ($operationCategory === '') {
            return $records;
        }

        if ($operationCategory === ApiMethodOperationClassifier::CATEGORY_UNCATEGORIZED) {
            return array_values(array_filter(
                $records,
                static fn(ApiMethodSummaryRecord $record): bool => $record->operationCategory === null,
            ));
        }

        return array_values(array_filter(
            $records,
            static fn(ApiMethodSummaryRecord $record): bool => $record->operationCategory === $operationCategory,
        ));
    }

    private function normalizeSelectorValue(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    private function hasInternalAnnotation(string|false $docComment): bool
    {
        return is_string($docComment) && str_contains($docComment, '@internal');
    }
}
