<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Tooling;

use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionSummaryRecord;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\DimensionsPagination;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;

/**
 * @phpstan-import-type DimensionSummaryArray from DimensionSummaryRecord
 */
final class DimensionSummaryPaginationResponder
{
    public function __construct(
        private ?CursorPaginator $paginator = null,
        private ?PaginationConfig $paginationConfig = null
    ) {
    }

    /**
     * @param array<int, DimensionSummaryRecord> $records
     * @return array{
     *     dimensions: list<DimensionSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    public function paginateDimensionSummaryRecords(
        array $records,
        ?int $limit = null,
        ?string $cursor = null,
        ?string $sort = null,
        ?string $cursorContext = null
    ): array {
        $sort = $sort ?? DimensionsPagination::SORT_NAME_ASC;
        /** @var list<DimensionSummaryArray> $resultDimensions */
        $resultDimensions = array_map(
            static fn(DimensionSummaryRecord $dimension): array => $dimension->toArray(),
            $records
        );

        $page = $this->getPaginator()->paginate(
            $resultDimensions,
            new PageRequest($limit, $sort, $cursor, $cursorContext),
            $this->getPaginationConfig()
        );

        return [
            'dimensions' => $page->items,
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
        return $this->paginationConfig ??= DimensionsPagination::createConfig();
    }
}
