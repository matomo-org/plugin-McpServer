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
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Segments\SegmentSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\SegmentList;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\SegmentsPagination;
use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @group McpServer
 * @group Plugins
 */
class SegmentListTest extends TestCase
{
    public function testListReturnsSortedSummariesFromApiWrapper(): void
    {
        $wrapper = new class () implements SegmentSummaryQueryServiceInterface {
            public function getSegmentSummariesForSite(int $idSite): array
            {
                return [
                    new SegmentSummaryRecord(2, 'Segment B', 'countryCode==fr', null),
                    new SegmentSummaryRecord(1, 'Segment A', 'countryCode==de', 1),
                ];
            }
        };

        $actual = (new SegmentList($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))->list(
            1,
            limit: 10,
            sort: SegmentsPagination::SORT_NAME_ASC
        );

        self::assertSame([
            'security' => ToolOutputSecurity::buildForTool(SegmentList::TOOL_NAME),
            'segments' => [
                ['idsegment' => 1, 'name' => 'Segment A', 'definition' => 'countryCode==de', 'idsite' => 1],
                ['idsegment' => 2, 'name' => 'Segment B', 'definition' => 'countryCode==fr', 'idsite' => null],
            ],
            'next_cursor' => null,
            'has_more' => false,
        ], $actual);
    }

    public function testListPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements SegmentSummaryQueryServiceInterface {
            public function getSegmentSummariesForSite(int $idSite): array
            {
                throw new ToolCallException("Segment list item is incomplete (missing 'definition').");
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Segment list item is incomplete (missing 'definition').");

        (new SegmentList($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))->list(1);
    }

    public function testListRejectsInvalidCursor(): void
    {
        $wrapper = new class () implements SegmentSummaryQueryServiceInterface {
            public function getSegmentSummariesForSite(int $idSite): array
            {
                return [];
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        (new SegmentList(
            $wrapper,
            new PaginatedCollectionResponder(new CursorPaginator())
        ))->list(1, cursor: 'invalid');
    }

    public function testListRejectsCursorSortMismatch(): void
    {
        $wrapper = new class () implements SegmentSummaryQueryServiceInterface {
            public function getSegmentSummariesForSite(int $idSite): array
            {
                return [
                    new SegmentSummaryRecord(1, 'Segment A', 'countryCode==de', 1),
                    new SegmentSummaryRecord(2, 'Segment B', 'countryCode==fr', 1),
                ];
            }
        };

        $tool = new SegmentList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));
        $page = $tool->list(1, limit: 1, sort: SegmentsPagination::SORT_ID_DESC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(1, cursor: $cursor, sort: SegmentsPagination::SORT_NAME_ASC);
    }

    public function testListRejectsCursorFromDifferentSiteContext(): void
    {
        $wrapper = new class () implements SegmentSummaryQueryServiceInterface {
            public function getSegmentSummariesForSite(int $idSite): array
            {
                return [
                    new SegmentSummaryRecord(1, 'Segment A', 'countryCode==de', $idSite),
                    new SegmentSummaryRecord(2, 'Segment B', 'countryCode==fr', $idSite),
                ];
            }
        };

        $tool = new SegmentList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));
        $page = $tool->list(1, limit: 1, sort: SegmentsPagination::SORT_ID_ASC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(2, cursor: $cursor, sort: SegmentsPagination::SORT_ID_ASC);
    }
}
