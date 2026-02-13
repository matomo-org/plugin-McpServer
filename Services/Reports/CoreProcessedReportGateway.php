<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Reports;

use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Plugins\API\ProcessedReport;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;

final class CoreProcessedReportGateway implements CoreProcessedReportGatewayInterface
{
    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): mixed
    {
        return StaticContainer::get(ProcessedReport::class)
            ->getReportMetadataByUniqueId($idSite, $reportUniqueId);
    }

    public function getReportMetadata(
        int $idSite,
        string $period,
        Date|bool $date,
        bool $hideMetricsDoc,
        bool $showSubtableReports
    ): mixed {
        return StaticContainer::get(ProcessedReport::class)
            ->getReportMetadata($idSite, $period, $date, $hideMetricsDoc, $showSubtableReports);
    }
}
