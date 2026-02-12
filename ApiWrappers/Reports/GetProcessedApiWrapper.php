<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\Reports;

use Piwik\Plugins\McpServer\Contracts\Reports\GetProcessedApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\Services\Reports\ReportProcessedQueryService;

/**
 * @phpstan-import-type ReportProcessedArray from \Piwik\Plugins\McpServer\Contracts\Reports\ReportProcessedRecord
 */
final class GetProcessedApiWrapper implements GetProcessedApiWrapperInterface
{
    public function __construct(private ?ReportProcessedQueryServiceInterface $queryService = null)
    {
    }

    /**
     * @param list<int|string>|null $goalMetricsProcessGoals
     * @return ReportProcessedArray
     */
    public function getProcessedReport(
        int $idSite,
        string $period,
        string $date,
        ?string $reportUniqueId,
        ?string $apiModule,
        ?string $apiAction,
        ?array $apiParameters,
        ?string $goalMetricsMode,
        ?array $goalMetricsProcessGoals,
        ?string $segment,
        int|string|null $idGoal,
        ?int $idDimension,
        ?int $idSubtable,
        int $filterLimit,
        int $filterOffset
    ): array {
        return $this->getQueryService()->getProcessedReport(
            $idSite,
            $period,
            $date,
            $reportUniqueId,
            $apiModule,
            $apiAction,
            $apiParameters,
            $goalMetricsMode,
            $goalMetricsProcessGoals,
            $segment,
            $idGoal,
            $idDimension,
            $idSubtable,
            $filterLimit,
            $filterOffset
        )->toArray();
    }

    private function getQueryService(): ReportProcessedQueryServiceInterface
    {
        return $this->queryService ??= new ReportProcessedQueryService();
    }
}
