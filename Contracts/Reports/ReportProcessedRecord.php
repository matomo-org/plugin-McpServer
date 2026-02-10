<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Reports;

/**
 * @phpstan-type ReportProcessedPaginationArray array{
 *     filter_limit: int,
 *     filter_offset: int,
 *     returned_rows: int,
 *     has_more: bool,
 * }
 * @phpstan-type ReportProcessedResolvedReportArray array{
 *     uniqueId: string,
 *     apiModule: string,
 *     apiAction: string,
 *     apiParameters: array<string, mixed>,
 * }
 * @phpstan-type ReportProcessedArray array{
 *     report: array<string, mixed>,
 *     pagination: ReportProcessedPaginationArray,
 *     resolvedReport: ReportProcessedResolvedReportArray,
 * }
 */
final class ReportProcessedRecord
{
    /**
     * @param array<string, mixed> $report
     * @param array<string, mixed> $apiParameters
     */
    public function __construct(
        public readonly array $report,
        public readonly int $filterLimit,
        public readonly int $filterOffset,
        public readonly int $returnedRows,
        public readonly bool $hasMore,
        public readonly string $uniqueId,
        public readonly string $apiModule,
        public readonly string $apiAction,
        public readonly array $apiParameters
    ) {
    }

    /**
     * @return ReportProcessedArray
     */
    public function toArray(): array
    {
        return [
            'report' => $this->report,
            'pagination' => [
                'filter_limit' => $this->filterLimit,
                'filter_offset' => $this->filterOffset,
                'returned_rows' => $this->returnedRows,
                'has_more' => $this->hasMore,
            ],
            'resolvedReport' => [
                'uniqueId' => $this->uniqueId,
                'apiModule' => $this->apiModule,
                'apiAction' => $this->apiAction,
                'apiParameters' => $this->apiParameters,
            ],
        ];
    }
}
