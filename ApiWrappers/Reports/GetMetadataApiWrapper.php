<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\Reports;

use Piwik\Plugins\McpServer\Contracts\Reports\GetMetadataApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\Services\Reports\ReportMetadataQueryService;

final class GetMetadataApiWrapper implements GetMetadataApiWrapperInterface
{
    public function __construct(private ?ReportMetadataQueryServiceInterface $queryService = null)
    {
    }

    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
    {
        return $this->getQueryService()->getReportMetadataByUniqueId($idSite, $reportUniqueId);
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
        return $this->getQueryService()->getReportMetadataByModuleAction(
            $idSite,
            $apiModule,
            $apiAction,
            $apiParameters,
            $period,
            $date
        );
    }

    private function getQueryService(): ReportMetadataQueryServiceInterface
    {
        return $this->queryService ??= new ReportMetadataQueryService();
    }
}
