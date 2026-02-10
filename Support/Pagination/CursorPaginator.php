<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Pagination;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;

final class CursorPaginator
{
    /**
     * String comparison policy:
     * - Uses locale-independent binary ordering (`strcmp`) for deterministic behavior.
     * - Comparison is case-sensitive and accent-sensitive.
     * - No locale/collation tailoring is applied at this layer.
     *
     * @template TItem of array<string, mixed>
     * @param list<TItem> $items
     * @return PageResult<TItem>
     */
    public function paginate(array $items, PageRequest $request, PaginationConfig $config): PageResult
    {
        $limit = $request->limit ?? $config->defaultLimit;
        if ($limit < 1 || $limit > $config->maxLimit) {
            throw new ToolCallException("Parameter 'limit' missing or invalid.");
        }

        $sortToken = $request->sortToken ?? $config->defaultSortToken;
        $sortSpec = $config->getSortSpec($sortToken);
        if ($sortSpec === null) {
            throw new ToolCallException("Parameter 'sort' missing or invalid.");
        }

        /** @var list<TItem> $rows */
        $rows = array_values($items);
        usort($rows, fn(array $left, array $right): int => $this->compareRows($left, $right, $sortSpec));

        if ($request->cursor !== null) {
            $boundary = $this->decodeCursor($request->cursor, $sortSpec, $request->cursorContext);
            $rows = $this->filterRowsAfterBoundary($rows, $sortSpec, $boundary);
        }

        $totalRows = count($rows);
        /** @var list<TItem> $rows */
        $rows = array_values(array_slice($rows, 0, $limit));
        $hasMore = $totalRows > $limit;
        $nextCursor = null;

        if ($hasMore && $rows !== []) {
            $nextCursor = $this->encodeCursor($sortSpec, $rows[count($rows) - 1], $request->cursorContext);
        }

        return new PageResult($rows, $nextCursor, $hasMore);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function encodeCursor(SortSpec $sortSpec, array $row, ?string $cursorContext): string
    {
        $last = [];
        foreach ($sortSpec->keyChain() as $keySpec) {
            $last[$keySpec->key] = $this->readRowValue($row, $keySpec, 'Pagination data');
        }

        $payload = [
            'v' => 1,
            'sort' => $sortSpec->token,
            'last' => $last,
        ];
        if ($cursorContext !== null) {
            $payload['ctx'] = $cursorContext;
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        return base64_encode($json);
    }

    /**
     * @return array<string, int|string>
     */
    private function decodeCursor(string $cursor, SortSpec $sortSpec, ?string $cursorContext): array
    {
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            throw new ToolCallException('Invalid cursor.');
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new ToolCallException('Invalid cursor.');
        }
        if (($payload['v'] ?? null) !== 1) {
            throw new ToolCallException('Invalid cursor.');
        }
        if (($payload['sort'] ?? null) !== $sortSpec->token) {
            throw new ToolCallException('Invalid cursor.');
        }
        if (!isset($payload['last']) || !is_array($payload['last'])) {
            throw new ToolCallException('Invalid cursor.');
        }
        if ($cursorContext !== null) {
            $context = $payload['ctx'] ?? null;
            if (!is_string($context) || $context !== $cursorContext) {
                throw new ToolCallException('Invalid cursor.');
            }
        }

        $last = [];
        foreach ($sortSpec->keyChain() as $keySpec) {
            $value = $payload['last'][$keySpec->key] ?? null;
            if ($value === null) {
                throw new ToolCallException('Invalid cursor.');
            }

            if ($keySpec->type === KeySpec::TYPE_INT) {
                if (is_int($value)) {
                    $last[$keySpec->key] = $value;
                    continue;
                }
                if (is_string($value) && ctype_digit($value)) {
                    $last[$keySpec->key] = (int) $value;
                    continue;
                }

                throw new ToolCallException('Invalid cursor.');
            }

            if (!is_string($value)) {
                throw new ToolCallException('Invalid cursor.');
            }
            $last[$keySpec->key] = $value;
        }

        return $last;
    }

    /**
     * @template TItem of array<string, mixed>
     * @param list<TItem> $rows
     * @param array<string, int|string> $boundary
     * @return list<TItem>
     */
    private function filterRowsAfterBoundary(array $rows, SortSpec $sortSpec, array $boundary): array
    {
        $result = [];
        foreach ($rows as $row) {
            if ($this->compareRowToBoundary($row, $sortSpec, $boundary) > 0) {
                $result[] = $row;
            }
        }

        /** @var list<TItem> $result */
        return $result;
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function compareRows(array $left, array $right, SortSpec $sortSpec): int
    {
        foreach ($sortSpec->keyChain() as $keySpec) {
            $leftValue = $this->readRowValue($left, $keySpec, 'Pagination data');
            $rightValue = $this->readRowValue($right, $keySpec, 'Pagination data');
            $comparison = $this->compareValues($leftValue, $rightValue, $keySpec->type);
            if ($comparison !== 0) {
                return $keySpec->direction === SortDirection::ASC ? $comparison : -$comparison;
            }
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, int|string> $boundary
     */
    private function compareRowToBoundary(array $row, SortSpec $sortSpec, array $boundary): int
    {
        foreach ($sortSpec->keyChain() as $keySpec) {
            $rowValue = $this->readRowValue($row, $keySpec, 'Pagination data');
            $boundaryValue = $boundary[$keySpec->key];
            $comparison = $this->compareValues($rowValue, $boundaryValue, $keySpec->type);
            if ($comparison !== 0) {
                return $keySpec->direction === SortDirection::ASC ? $comparison : -$comparison;
            }
        }

        return 0;
    }

    /**
     * @param int|string $left
     * @param int|string $right
     */
    private function compareValues(int|string $left, int|string $right, string $type): int
    {
        if ($type === KeySpec::TYPE_INT) {
            return $left <=> $right;
        }

        return $this->compareStringValues((string) $left, (string) $right);
    }

    private function compareStringValues(string $left, string $right): int
    {
        return strcmp($left, $right);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function readRowValue(array $row, KeySpec $keySpec, string $context): int|string
    {
        if (!array_key_exists($keySpec->key, $row) || $row[$keySpec->key] === null) {
            throw new ToolCallException("{$context} is incomplete (missing '{$keySpec->key}').");
        }
        $value = $row[$keySpec->key];

        if ($keySpec->type === KeySpec::TYPE_INT) {
            if (is_int($value)) {
                return $value;
            }
            if (is_string($value) && ctype_digit($value)) {
                return (int) $value;
            }

            throw new ToolCallException("{$context} is invalid (field '{$keySpec->key}').");
        }

        if (!is_string($value)) {
            throw new ToolCallException("{$context} is invalid (field '{$keySpec->key}').");
        }

        return $value;
    }
}
