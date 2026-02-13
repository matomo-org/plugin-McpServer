<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Reports;

use Piwik\Date;
use Piwik\Plugins\API\ProcessedReport;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;

final class CoreProcessedReportGateway implements CoreProcessedReportGatewayInterface
{
    public function __construct(private ProcessedReport $processedReport)
    {
    }

    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): mixed
    {
        return $this->processedReport->getReportMetadataByUniqueId($idSite, $reportUniqueId);
    }

    public function getReportMetadata(
        int $idSite,
        string $period,
        Date|bool $date,
        bool $hideMetricsDoc,
        bool $showSubtableReports
    ): mixed {
        return $this->processedReport->getReportMetadata(
            $idSite,
            $period,
            $date,
            $hideMetricsDoc,
            $showSubtableReports
        );
    }
}
