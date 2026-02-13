<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Reports;

use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportSummaryRecord;

interface ReportSummaryQueryServiceInterface
{
    /**
     * @return array<int, ReportSummaryRecord>
     */
    public function getReportSummariesForSite(int $idSite): array;
}
