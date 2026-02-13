<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Reports;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Date;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class ReportMetadataQueryService implements ReportMetadataQueryServiceInterface
{
    public function __construct(
        private CoreProcessedReportGatewayInterface $coreProcessedReportGateway,
        private TranslatorContextRunnerInterface $translatorContextRunner
    ) {
    }

    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
    {
        try {
            $metadata = $this->translatorContextRunner->runInEnglish(
                function () use ($idSite, $reportUniqueId): mixed {
                    return $this->coreProcessedReportGateway->getReportMetadataByUniqueId($idSite, $reportUniqueId);
                }
            );
        } catch (NoAccessException $e) {
            throw new ToolCallException('Report not found.');
        } catch (\Throwable $e) {
            if (ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
                throw new ToolCallException('Report not found.');
            }

            throw new ToolCallException('Report retrieval failed.');
        }

        if (!is_array($metadata)) {
            throw new ToolCallException('Report not found.');
        }

        $metadataData = ToolDataNormalizer::requireStringKeyedArray($metadata, 'Report not found.');
        if ($this->isSubtableReport($metadataData)) {
            throw new ToolCallException('Report not found.');
        }

        return $this->normalizeReportMetadataData($metadataData, 'Report metadata item');
    }

    /**
     * @param array<string, mixed> $apiParameters
     */
    public function getReportMetadataByModuleAction(
        int $idSite,
        string $apiModule,
        string $apiAction,
        array $apiParameters,
        string $period,
        string $date
    ): ReportMetadataRecord {
        $normalizedApiParameters = $this->normalizeParameterObject($apiParameters, 'apiParameters');
        $metadataDate = $this->normalizeReportMetadataDate($date);

        try {
            $reports = $this->translatorContextRunner->runInEnglish(
                function () use ($idSite, $period, $metadataDate): mixed {
                    return $this->coreProcessedReportGateway->getReportMetadata(
                        $idSite,
                        $period,
                        $metadataDate,
                        false,
                        false
                    );
                }
            );
        } catch (NoAccessException $e) {
            throw new ToolCallException('Report not found.');
        } catch (\Throwable $e) {
            if (ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
                throw new ToolCallException('Report not found.');
            }

            throw new ToolCallException('Report retrieval failed.');
        }

        if (!is_array($reports)) {
            throw new ToolCallException('Report metadata data is invalid.');
        }

        $matches = [];
        foreach ($reports as $report) {
            if (!is_array($report)) {
                continue;
            }

            $reportData = ToolDataNormalizer::requireStringKeyedArray($report, 'Report metadata data is invalid.');
            if ($this->isSubtableReport($reportData)) {
                continue;
            }

            $module = $reportData['module'] ?? null;
            $action = $reportData['action'] ?? null;

            if (!is_string($module) || !is_string($action)) {
                throw new ToolCallException('Report metadata data is invalid.');
            }

            if ($module !== $apiModule || $action !== $apiAction) {
                continue;
            }

            $reportParametersRaw = $reportData['parameters'] ?? [];
            if (!is_array($reportParametersRaw)) {
                throw new ToolCallException("Report metadata item is invalid (field 'parameters').");
            }
            $reportParameters = $this->normalizeParameterObject($reportParametersRaw, 'parameters');
            if (!$this->parametersAreEquivalent($reportParameters, $normalizedApiParameters)) {
                continue;
            }

            $matches[] = $reportData;
        }

        if ($matches === []) {
            throw new ToolCallException('Report not found.');
        }

        if (count($matches) > 1) {
            throw new ToolCallException('Multiple reports matched. Provide reportUniqueId.');
        }

        return $this->normalizeReportMetadataData($matches[0], 'Report metadata item');
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $report
     */
    public function normalizeReportMetadataData(array $report, string $context): ReportMetadataRecord
    {
        $parametersRaw = $report['parameters'] ?? [];
        if (!is_array($parametersRaw)) {
            throw new ToolCallException("{$context} is invalid (field 'parameters').");
        }
        $parameters = $this->normalizeParameterObject($parametersRaw, 'parameters');

        return new ReportMetadataRecord(
            uniqueId: ToolDataNormalizer::requireStringField($report, 'uniqueId', $context),
            module: ToolDataNormalizer::requireStringField($report, 'module', $context),
            action: ToolDataNormalizer::requireStringField($report, 'action', $context),
            name: ToolDataNormalizer::requireStringField($report, 'name', $context),
            category: ToolDataNormalizer::requireStringField($report, 'category', $context),
            parameters: $parameters,
            metadata: $report,
        );
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function parametersAreEquivalent(array $left, array $right): bool
    {
        if (count($left) !== count($right)) {
            return false;
        }

        foreach ($left as $key => $value) {
            if (!array_key_exists($key, $right)) {
                return false;
            }

            if ($this->normalizeParameterValue($value) !== $this->normalizeParameterValue($right[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $value
     * @return array<string, mixed>
     */
    private function normalizeParameterObject(mixed $value, string $field): array
    {
        return ToolDataNormalizer::requireStringKeyedArray(
            $value,
            "Report metadata item is invalid (field '{$field}')"
        );
    }

    private function normalizeParameterValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return (string) $value;
        }

        if (is_array($value)) {
            return json_encode($this->sortArrayRecursively($value), JSON_THROW_ON_ERROR);
        }

        throw new ToolCallException("Report metadata item is invalid (field 'parameters').");
    }

    private function normalizeReportMetadataDate(string $date): Date
    {
        try {
            return Date::factory($date);
        } catch (\Throwable $e) {
            throw new ToolCallException("Invalid date parameter '{$date}'.");
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function sortArrayRecursively(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortArrayRecursively($item);
            }
        }

        $keys = array_keys($value);
        $allStringKeys = true;
        foreach ($keys as $key) {
            if (!is_string($key)) {
                $allStringKeys = false;
                break;
            }
        }

        if ($allStringKeys) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function isSubtableReport(array $report): bool
    {
        $primary = $report['isSubtableReport'] ?? null;
        if ($primary === true || $primary === 1 || $primary === '1') {
            return true;
        }

        $alias = $report['isSubtableReports'] ?? null;
        return $alias === true || $alias === 1 || $alias === '1';
    }
}
