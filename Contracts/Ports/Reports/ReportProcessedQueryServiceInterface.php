<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Reports;

use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportProcessedRecord;

interface ReportProcessedQueryServiceInterface
{
    /**
     * @param array<string, mixed>|null $apiParameters
     * @param list<int|string>|null $goalMetricsProcessGoals
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
    ): ReportProcessedRecord;
}
