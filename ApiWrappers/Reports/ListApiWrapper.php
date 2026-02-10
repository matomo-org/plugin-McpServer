<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\Reports;

use Piwik\Plugins\McpServer\Contracts\Reports\ListApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Reports\ReportSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Reports\ReportSummaryRecord;
use Piwik\Plugins\McpServer\Services\Reports\ReportSummaryQueryService;

final class ListApiWrapper implements ListApiWrapperInterface
{
    public function __construct(private ?ReportSummaryQueryServiceInterface $queryService = null)
    {
    }

    /**
     * @return array<int, ReportSummaryRecord>
     */
    public function getReportsForSite(int $idSite): array
    {
        return $this->getQueryService()->getReportSummariesForSite($idSite);
    }

    private function getQueryService(): ReportSummaryQueryServiceInterface
    {
        return $this->queryService ??= new ReportSummaryQueryService();
    }
}
