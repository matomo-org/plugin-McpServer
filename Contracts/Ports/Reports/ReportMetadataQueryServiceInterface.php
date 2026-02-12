<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Reports;

use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;

interface ReportMetadataQueryServiceInterface
{
    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord;

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
    ): ReportMetadataRecord;
}
