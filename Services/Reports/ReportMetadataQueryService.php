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
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Errors\InfrastructureDataException;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class ReportMetadataQueryService implements ReportMetadataQueryServiceInterface
{
    public function __construct(
        private CoreProcessedReportGatewayInterface $coreProcessedReportGateway,
        private TranslatorContextRunnerInterface $translatorContextRunner,
    ) {
    }

    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
    {
        try {
            $metadata = $this->translatorContextRunner->runInEnglish(
                function () use ($idSite, $reportUniqueId) {
                    return $this->coreProcessedReportGateway->getReportMetadataByUniqueId(
                        $idSite,
                        $reportUniqueId,
                    );
                },
            );
        } catch (NoAccessException) {
            throw new ToolCallException('Report not found.');
        } catch (InfrastructureDataException) {
            throw new ToolCallException('Report not found.');
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable) {
            if (ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
                throw new ToolCallException('Report not found.');
            }

            throw new ToolCallException('Report retrieval failed.');
        }

        $metadataData = ToolDataNormalizer::requireStringKeyedArray($metadata, 'Report not found.');
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
        string $date,
    ): ReportMetadataRecord {
        $normalizedApiParameters = $this->normalizeParameterObject($apiParameters, 'apiParameters');
        [$normalizedPeriod, $normalizedDate] = $this->normalizeReportMetadataPeriodAndDate($period, $date);

        try {
            $reports = $this->translatorContextRunner->runInEnglish(
                function () use ($idSite, $normalizedPeriod, $normalizedDate) {
                    return $this->coreProcessedReportGateway->getReportMetadata(
                        $idSite,
                        $normalizedPeriod,
                        $normalizedDate,
                        false,
                        true,
                    );
                },
            );
        } catch (NoAccessException) {
            throw new ToolCallException('Report not found.');
        } catch (InfrastructureDataException) {
            throw new ToolCallException('Report metadata data is invalid.');
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable) {
            if (ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
                throw new ToolCallException('Report not found.');
            }

            throw new ToolCallException('Report retrieval failed.');
        }

        /** @var list<array<string, mixed>> $reports */
        $matches = [];
        foreach ($reports as $report) {
            $module = $report['module'] ?? null;
            $action = $report['action'] ?? null;

            if (!is_string($module) || !is_string($action)) {
                throw new ToolCallException('Report metadata data is invalid.');
            }

            if ($module !== $apiModule || $action !== $apiAction) {
                continue;
            }

            $reportParametersRaw = $report['parameters'] ?? [];
            if (!is_array($reportParametersRaw)) {
                throw new ToolCallException("Report metadata item is invalid (field 'parameters').");
            }
            $reportParameters = $this->normalizeParameterObject($reportParametersRaw, 'parameters');
            if (!$this->parametersAreEquivalent($reportParameters, $normalizedApiParameters)) {
                continue;
            }

            $matches[] = $report;
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
            isSubtableReport: $this->isSubtableReport($report),
            actionToLoadSubTables: $this->normalizeOptionalStringField($report, 'actionToLoadSubTables', $context),
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
     * @return array<string, mixed>
     */
    private function normalizeParameterObject(mixed $value, string $field): array
    {
        return ToolDataNormalizer::requireStringKeyedArray(
            $value,
            "Report metadata item is invalid (field '{$field}')",
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

    /**
     * @return array{0: string, 1: Date|string}
     */
    private function normalizeReportMetadataPeriodAndDate(string $period, string $date): array
    {
        try {
            $normalizedPeriod = PeriodFactory::build($period, $date);
        } catch (\Throwable) {
            throw new ToolCallException('Invalid period/date parameters.');
        }

        if ($normalizedPeriod->getLabel() === 'range') {
            return ['range', $normalizedPeriod->getRangeString()];
        }

        return [$normalizedPeriod->getLabel(), $normalizedPeriod->getDateStart()];
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

    /**
     * @param array<string, mixed> $report
     */
    private function normalizeOptionalStringField(array $report, string $field, string $context): ?string
    {
        $value = $report[$field] ?? null;
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value)) {
            throw new ToolCallException("{$context} is invalid (field '{$field}').");
        }

        return $value;
    }
}
