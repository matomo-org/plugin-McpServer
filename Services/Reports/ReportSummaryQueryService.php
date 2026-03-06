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
use Piwik\API\Request;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportSummaryRecord;
use Piwik\Plugins\McpServer\Support\Errors\ToolErrorMapper;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class ReportSummaryQueryService implements ReportSummaryQueryServiceInterface
{
    /**
     * @return array<int, ReportSummaryRecord>
     */
    public function getReportSummariesForSite(int $idSite): array
    {
        try {
            $reports = Request::processRequest(
                'API.getReportMetadata',
                [
                    'idSite' => (string) $idSite,
                    'period' => false,
                    'date' => false,
                    'hideMetricsDoc' => true,
                    'showSubtableReports' => true,
                ],
                []
            );
        } catch (\Throwable $e) {
            // Keep list behavior aligned with other list tools: no view access yields no rows.
            if (
                ToolErrorMapper::shouldReturnEmptyListFor(
                    $e,
                    fn(\Throwable $error): bool => $this->isNoAccessLikeFailure($error)
                )
            ) {
                return [];
            }

            throw new ToolCallException('Report retrieval failed.');
        }

        return $this->normalizeReportSummaryRows(
            $reports,
            'Report list data is invalid.',
            'Report list item'
        );
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $report
     */
    public function normalizeReportSummaryData(array $report, string $context): ReportSummaryRecord
    {
        $parametersValue = $report['parameters'] ?? [];
        if ($parametersValue !== [] && !is_array($parametersValue)) {
            throw new ToolCallException("{$context} is invalid (field 'parameters').");
        }
        $parameters = ToolDataNormalizer::requireStringKeyedArray(
            $parametersValue,
            "{$context} is invalid (field 'parameters')"
        );

        return new ReportSummaryRecord(
            uniqueId: ToolDataNormalizer::requireStringField($report, 'uniqueId', $context),
            module: ToolDataNormalizer::requireStringField($report, 'module', $context),
            action: ToolDataNormalizer::requireStringField($report, 'action', $context),
            name: ToolDataNormalizer::requireStringField($report, 'name', $context),
            category: ToolDataNormalizer::requireStringField($report, 'category', $context),
            parameters: $parameters,
        );
    }

    /**
     * Public for testability and to keep top-level payload-shape validation centralized.
     *
     * @return array<int, ReportSummaryRecord>
     */
    public function normalizeReportSummaryRows(
        mixed $reports,
        string $invalidDataMessage,
        string $context
    ): array {
        if (!is_array($reports)) {
            throw new ToolCallException($invalidDataMessage);
        }

        $result = [];
        foreach ($reports as $report) {
            $reportData = ToolDataNormalizer::requireStringKeyedArray($report, $invalidDataMessage);

            if ($this->isSubtableReport($reportData)) {
                continue;
            }

            $result[] = $this->normalizeReportSummaryData($reportData, $context);
        }

        return $result;
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

    private function isNoAccessLikeFailure(\Throwable $e): bool
    {
        $message = strtolower(trim((string) $e->getMessage()));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'no access')
            || str_contains($message, 'checkuserhasviewaccess')
            || str_contains($message, 'view access');
    }
}
