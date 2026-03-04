<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\Records\Goals\GoalSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\McpTools\GoalList;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\GoalsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @group McpServer
 * @group Plugins
 */
class GoalListTest extends TestCase
{
    public function testListReturnsSortedSummariesFromApiWrapper(): void
    {
        $wrapper = new class () implements GoalSummaryQueryServiceInterface {
            public function getGoalSummariesForSite(int $idSite): array
            {
                return [
                    new GoalSummaryRecord(2, $idSite, 'Goal B', '', 'event_action', false, '0', false),
                    new GoalSummaryRecord(1, $idSite, 'Goal A', '', 'event_action', true, '10.5', true),
                ];
            }
        };

        $actual = (new GoalList($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))->list(
            1,
            limit: 10,
            sort: GoalsPagination::SORT_NAME_ASC
        );

        self::assertSame([
            'goals' => [
                [
                    'idgoal' => 1,
                    'idsite' => 1,
                    'name' => 'Goal A',
                    'description' => '',
                    'match_attribute' => 'event_action',
                    'allow_multiple' => true,
                    'revenue' => '10.5',
                    'event_value_as_revenue' => true,
                ],
                [
                    'idgoal' => 2,
                    'idsite' => 1,
                    'name' => 'Goal B',
                    'description' => '',
                    'match_attribute' => 'event_action',
                    'allow_multiple' => false,
                    'revenue' => '0',
                    'event_value_as_revenue' => false,
                ],
            ],
            'next_cursor' => null,
            'has_more' => false,
            'total_rows' => 2,
        ], $actual);
    }

    public function testListPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements GoalSummaryQueryServiceInterface {
            public function getGoalSummariesForSite(int $idSite): array
            {
                throw new ToolCallException("Goal list item is incomplete (missing 'name').");
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal list item is incomplete (missing 'name').");

        (new GoalList($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))->list(1);
    }

    public function testListRejectsInvalidCursor(): void
    {
        $wrapper = new class () implements GoalSummaryQueryServiceInterface {
            public function getGoalSummariesForSite(int $idSite): array
            {
                return [];
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        (new GoalList($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))->list(1, cursor: 'invalid');
    }

    public function testListRejectsCursorSortMismatch(): void
    {
        $wrapper = new class () implements GoalSummaryQueryServiceInterface {
            public function getGoalSummariesForSite(int $idSite): array
            {
                return [
                    new GoalSummaryRecord(1, $idSite, 'Goal A', '', 'event_action', false, '0', false),
                    new GoalSummaryRecord(2, $idSite, 'Goal B', '', 'event_action', false, '0', false),
                ];
            }
        };

        $tool = new GoalList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));
        $page = $tool->list(1, limit: 1, sort: GoalsPagination::SORT_ID_DESC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(1, cursor: $cursor, sort: GoalsPagination::SORT_NAME_ASC);
    }

    public function testListRejectsCursorFromDifferentSiteContext(): void
    {
        $wrapper = new class () implements GoalSummaryQueryServiceInterface {
            public function getGoalSummariesForSite(int $idSite): array
            {
                return [
                    new GoalSummaryRecord(1, $idSite, 'Goal A', '', 'event_action', false, '0', false),
                    new GoalSummaryRecord(2, $idSite, 'Goal B', '', 'event_action', false, '0', false),
                ];
            }
        };

        $tool = new GoalList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));
        $page = $tool->list(1, limit: 1, sort: GoalsPagination::SORT_ID_ASC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(2, cursor: $cursor, sort: GoalsPagination::SORT_ID_ASC);
    }
}
