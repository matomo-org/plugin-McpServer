<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Tooling;

use Piwik\Plugins\McpServer\Contracts\Segments\SegmentSummaryRecord;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;
use Piwik\Plugins\McpServer\Support\Pagination\SegmentsPagination;

/**
 * @phpstan-import-type SegmentSummaryArray from SegmentSummaryRecord
 */
final class SegmentSummaryPaginationResponder
{
    public function __construct(
        private ?CursorPaginator $paginator = null,
        private ?PaginationConfig $paginationConfig = null
    ) {
    }

    /**
     * @param array<int, SegmentSummaryRecord> $records
     * @return array{
     *     segments: list<SegmentSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    public function paginateSegmentSummaryRecords(
        array $records,
        ?int $limit = null,
        ?string $cursor = null,
        ?string $sort = null,
        ?string $cursorContext = null
    ): array {
        $sort = $sort ?? SegmentsPagination::SORT_NAME_ASC;
        /** @var list<SegmentSummaryArray> $resultSegments */
        $resultSegments = array_map(
            static fn(SegmentSummaryRecord $segment): array => $segment->toArray(),
            $records
        );

        $page = $this->getPaginator()->paginate(
            $resultSegments,
            new PageRequest($limit, $sort, $cursor, $cursorContext),
            $this->getPaginationConfig()
        );

        return [
            'segments' => $page->items,
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
        return $this->paginationConfig ??= SegmentsPagination::createConfig();
    }
}
