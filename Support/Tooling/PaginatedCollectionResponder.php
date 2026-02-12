<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Tooling;

use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;

final class PaginatedCollectionResponder
{
    public function __construct(
        private ?CursorPaginator $paginator = null
    ) {
    }

    /**
     * @template TRecord
     * @template TItem of array<string, mixed>
     * @param array<int, TRecord> $records
     * @param callable(TRecord): TItem $recordToArray
     * @return array<string, mixed>
     */
    public function paginateRecords(
        array $records,
        callable $recordToArray,
        string $collectionKey,
        PaginationConfig $paginationConfig,
        string $defaultSortToken,
        ?int $limit = null,
        ?string $cursor = null,
        ?string $sort = null,
        ?string $cursorContext = null
    ): array {
        $sort = $sort ?? $defaultSortToken;
        /** @var list<TItem> $items */
        $items = array_map($recordToArray, $records);

        $page = $this->getPaginator()->paginate(
            $items,
            new PageRequest($limit, $sort, $cursor, $cursorContext),
            $paginationConfig
        );

        return [
            $collectionKey => $page->items,
            'next_cursor' => $page->nextCursor,
            'has_more' => $page->hasMore,
        ];
    }

    private function getPaginator(): CursorPaginator
    {
        return $this->paginator ??= new CursorPaginator();
    }
}
