<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Tooling;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Plugins\McpServer\Support\Pagination\KeySpec;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;

final class PaginatedCollectionResponder
{
    public function __construct(private CursorPaginator $paginator)
    {
    }

    /**
     * @template TRecord
     * @template TItem of array<string, mixed>
     * @template TSortData of array<string, mixed>
     * @param array<int, TRecord> $records
     * @param callable(TRecord): TItem $recordToArray
     * @param callable(TRecord): TSortData|null $recordToSortData
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
        ?string $cursorContext = null,
        ?callable $recordToSortData = null
    ): array {
        $sort = $sort ?? $defaultSortToken;
        $sortSpec = $paginationConfig->getSortSpec($sort);
        if ($sortSpec === null) {
            throw new ToolCallException("Parameter 'sort' missing or invalid.");
        }

        $sortDataResolver = $recordToSortData ?? $recordToArray;
        $internalRecordKey = $this->resolveInternalRecordKey($sortSpec->keyChain());

        /** @var list<array<string, mixed>> $rows */
        $rows = [];
        foreach ($records as $record) {
            /** @var array<string, mixed> $sortData */
            $sortData = $sortDataResolver($record);
            $row = [$internalRecordKey => $record];
            foreach ($sortSpec->keyChain() as $keySpec) {
                if (array_key_exists($keySpec->key, $sortData)) {
                    $row[$keySpec->key] = $sortData[$keySpec->key];
                }
            }
            $rows[] = $row;
        }

        $page = $this->paginator->paginate(
            $rows,
            new PageRequest($limit, $sort, $cursor, $cursorContext),
            $paginationConfig
        );

        /** @var list<TItem> $items */
        $items = [];
        foreach ($page->items as $row) {
            if (!array_key_exists($internalRecordKey, $row)) {
                throw new ToolCallException('Pagination data is invalid.');
            }
            $record = $row[$internalRecordKey];
            /** @var TRecord $record */
            $items[] = $recordToArray($record);
        }

        return [
            $collectionKey => $items,
            'next_cursor' => $page->nextCursor,
            'has_more' => $page->hasMore,
        ];
    }

    /**
     * @param array<int, KeySpec> $keyChain
     */
    private function resolveInternalRecordKey(array $keyChain): string
    {
        $usedKeys = [];
        foreach ($keyChain as $keySpec) {
            $usedKeys[$keySpec->key] = true;
        }

        $candidate = '__mcp_internal_record';
        while (isset($usedKeys[$candidate])) {
            $candidate .= '_';
        }

        return $candidate;
    }
}
