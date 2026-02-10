<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Tooling;

use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryRecord;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\GoalsPagination;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;

/**
 * @phpstan-import-type GoalSummaryArray from GoalSummaryRecord
 */
final class GoalSummaryPaginationResponder
{
    public function __construct(
        private ?CursorPaginator $paginator = null,
        private ?PaginationConfig $paginationConfig = null
    ) {
    }

    /**
     * @param array<int, GoalSummaryRecord> $records
     * @return array{
     *     goals: list<GoalSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    public function paginateGoalSummaryRecords(
        array $records,
        ?int $limit = null,
        ?string $cursor = null,
        ?string $sort = null,
        ?string $cursorContext = null
    ): array {
        $sort = $sort ?? GoalsPagination::SORT_NAME_ASC;
        /** @var list<GoalSummaryArray> $resultGoals */
        $resultGoals = array_map(
            static fn(GoalSummaryRecord $goal): array => $goal->toArray(),
            $records
        );

        $page = $this->getPaginator()->paginate(
            $resultGoals,
            new PageRequest($limit, $sort, $cursor, $cursorContext),
            $this->getPaginationConfig()
        );

        return [
            'goals' => $page->items,
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
        return $this->paginationConfig ??= GoalsPagination::createConfig();
    }
}
