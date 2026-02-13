<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Reports;

use Piwik\Date;

interface CoreProcessedReportGatewayInterface
{
    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): mixed;

    public function getReportMetadata(
        int $idSite,
        string $period,
        Date|bool $date,
        bool $hideMetricsDoc,
        bool $showSubtableReports
    ): mixed;
}
