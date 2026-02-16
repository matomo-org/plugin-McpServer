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
use Piwik\Plugins\API\ProcessedReport;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class CoreProcessedReportGateway implements CoreProcessedReportGatewayInterface
{
    public function __construct(private ProcessedReport $processedReport)
    {
    }

    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
    {
        $metadata = $this->processedReport->getReportMetadataByUniqueId($idSite, $reportUniqueId);
        return ToolDataNormalizer::requireStringKeyedArray($metadata, 'Report not found.');
    }

    public function getReportMetadata(
        int $idSite,
        string $period,
        Date|bool $date,
        bool $hideMetricsDoc,
        bool $showSubtableReports
    ): array {
        $reports = $this->processedReport->getReportMetadata(
            $idSite,
            $period,
            $date,
            $hideMetricsDoc,
            $showSubtableReports
        );

        // @phpstan-ignore-next-line Runtime guard for unexpected core payloads.
        if (!is_array($reports)) {
            throw new ToolCallException('Report metadata data is invalid.');
        }

        if (!array_is_list($reports)) {
            throw new ToolCallException('Report metadata data is invalid.');
        }

        $normalized = [];
        foreach ($reports as $report) {
            $normalized[] = ToolDataNormalizer::requireStringKeyedArray($report, 'Report metadata data is invalid.');
        }

        return $normalized;
    }
}
