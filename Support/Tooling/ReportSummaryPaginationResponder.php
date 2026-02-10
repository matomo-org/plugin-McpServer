<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Tooling;

use Piwik\Plugins\McpServer\Contracts\Reports\ReportSummaryRecord;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;
use Piwik\Plugins\McpServer\Support\Pagination\ReportsPagination;

/**
 * @phpstan-import-type ReportSummaryArray from ReportSummaryRecord
 */
final class ReportSummaryPaginationResponder
{
    public function __construct(
        private ?CursorPaginator $paginator = null,
        private ?PaginationConfig $paginationConfig = null
    ) {
    }

    /**
     * @param array<int, ReportSummaryRecord> $records
     * @return array{
     *     reports: list<ReportSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    public function paginateReportSummaryRecords(
        array $records,
        ?int $limit = null,
        ?string $cursor = null,
        ?string $sort = null,
        ?string $cursorContext = null
    ): array {
        $sort = $sort ?? ReportsPagination::SORT_CATEGORY_ASC;
        /** @var list<ReportSummaryArray> $resultReports */
        $resultReports = array_map(
            static fn(ReportSummaryRecord $report): array => $report->toArray(),
            $records
        );

        $page = $this->getPaginator()->paginate(
            $resultReports,
            new PageRequest($limit, $sort, $cursor, $cursorContext),
            $this->getPaginationConfig()
        );

        return [
            'reports' => $page->items,
            'next_cursor' => $page->nextCursor,
            'has_more' => $page->hasMore,
        ];
    }

    private function getPaginator(): CursorPaginator
    {
        return $this->paginator ??= new CursorPaginator();
    }

    private function getPaginationConfig(): PaginationConfig
    {
        return $this->paginationConfig ??= ReportsPagination::createConfig();
    }
}
