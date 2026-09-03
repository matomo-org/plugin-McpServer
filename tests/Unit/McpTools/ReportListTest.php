<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\McpTools;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\ReportList;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\ReportsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportListTest extends TestCase
{
    public function testListReturnsSortedSummariesFromApiWrapper(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                return [
                    new ReportSummaryRecord(
                        'VisitsSummary_get',
                        'VisitsSummary',
                        'get',
                        'Overview',
                        'Visits Summary',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Actions',
                        ['foo' => 'bar'],
                    ),
                ];
            }
        };

        $actual = (new ReportList($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))->execute(
            1,
            limit: 10,
            sort: ReportsPagination::SORT_CATEGORY_ASC,
        );

        self::assertEquals([
            'reports' => [
                [
                    'uniqueId' => 'Actions_getPageUrls',
                    'module' => 'Actions',
                    'action' => 'getPageUrls',
                    'name' => 'Page URLs',
                    'category' => 'Actions',
                    'parameters' => ['foo' => 'bar'],
                    'isSubtableReport' => false,
                    'actionToLoadSubTables' => null,
                ],
                [
                    'uniqueId' => 'VisitsSummary_get',
                    'module' => 'VisitsSummary',
                    'action' => 'get',
                    'name' => 'Overview',
                    'category' => 'Visits Summary',
                    'parameters' => new \stdClass(),
                    'isSubtableReport' => false,
                    'actionToLoadSubTables' => null,
                ],
            ],
            'next_cursor' => null,
            'has_more' => false,
            'total_rows' => 2,
        ], $actual);
    }

    public function testSearchFiltersByNameCategoryOrUniqueIdCaseInsensitively(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                return [
                    new ReportSummaryRecord(
                        'VisitsSummary_get',
                        'VisitsSummary',
                        'get',
                        'Visits Summary',
                        'Visitors',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Behaviour',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'Referrers_getAll',
                        'Referrers',
                        'getAll',
                        'All Channels',
                        'Acquisition',
                        [],
                    ),
                ];
            }
        };

        $tool = new ReportList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));

        // Matches on the human name.
        $byName = $tool->execute(1, search: 'page urls');
        self::assertSame(['Actions_getPageUrls'], array_column($byName['reports'], 'uniqueId'));

        // Matches on the uniqueId even though the name ("All Channels") does not contain the term.
        $byUniqueId = $tool->execute(1, search: 'referrers');
        self::assertSame(['Referrers_getAll'], array_column($byUniqueId['reports'], 'uniqueId'));

        // Matches on the category, case-insensitively.
        $byCategory = $tool->execute(1, search: 'VISITORS');
        self::assertSame(['VisitsSummary_get'], array_column($byCategory['reports'], 'uniqueId'));

        // A term that matches nothing yields an empty, well-formed page.
        $none = $tool->execute(1, search: 'no-such-report');
        self::assertSame([], $none['reports']);
        self::assertSame(0, $none['total_rows']);
        self::assertFalse($none['has_more']);
        self::assertNull($none['next_cursor']);
    }

    public function testSearchIgnoresSeparatorsAcrossSpellings(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                return [
                    new ReportSummaryRecord(
                        'VisitsSummary_get',
                        'VisitsSummary',
                        'get',
                        'Visits Summary',
                        'Visitors',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Behaviour',
                        [],
                    ),
                ];
            }
        };

        $tool = new ReportList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));

        // Spaced human phrasing matches the camelCase uniqueId (no raw substring overlap).
        self::assertSame(
            ['VisitsSummary_get'],
            array_column($tool->execute(1, search: 'Visits Summary')['reports'], 'uniqueId'),
        );

        // The dotted method spelling a caller often guesses as a uniqueId also matches.
        self::assertSame(
            ['VisitsSummary_get'],
            array_column($tool->execute(1, search: 'VisitsSummary.get')['reports'], 'uniqueId'),
        );

        // Unspaced query matches the spaced human name.
        self::assertSame(
            ['Actions_getPageUrls'],
            array_column($tool->execute(1, search: 'pageurls')['reports'], 'uniqueId'),
        );
    }

    public function testListPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                throw new McpToolCallException("Report list item is incomplete (missing 'name').");
            }
        };

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Report list item is incomplete (missing 'name').");

        (new ReportList($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))->execute(1);
    }

    public function testListRejectsInvalidCursor(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                return [];
            }
        };

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        (new ReportList($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))
            ->execute(1, cursor: 'invalid');
    }

    public function testListRejectsCursorSortMismatch(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                return [
                    new ReportSummaryRecord(
                        'VisitsSummary_get',
                        'VisitsSummary',
                        'get',
                        'Overview',
                        'Visits',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Actions',
                        [],
                    ),
                ];
            }
        };

        $tool = new ReportList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));
        $page = $tool->execute(1, limit: 1, sort: ReportsPagination::SORT_NAME_DESC);
        $cursor = $page['next_cursor'];
        self::assertIsString($cursor);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->execute(1, cursor: $cursor, sort: ReportsPagination::SORT_NAME_ASC);
    }

    public function testListRejectsCursorFromDifferentSiteContext(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                return [
                    new ReportSummaryRecord(
                        'VisitsSummary_get',
                        'VisitsSummary',
                        'get',
                        'Overview',
                        'Visits',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Actions',
                        [],
                    ),
                ];
            }
        };

        $tool = new ReportList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));
        $page = $tool->execute(1, limit: 1, sort: ReportsPagination::SORT_NAME_ASC);
        $cursor = $page['next_cursor'];
        self::assertIsString($cursor);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->execute(2, cursor: $cursor, sort: ReportsPagination::SORT_NAME_ASC);
    }

    public function testListRejectsCursorWhenSearchChanges(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                return [
                    new ReportSummaryRecord(
                        'VisitsSummary_get',
                        'VisitsSummary',
                        'get',
                        'Overview',
                        'Visitors',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'VisitFrequency_get',
                        'VisitFrequency',
                        'get',
                        'Visit Frequency',
                        'Visitors',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Behaviour',
                        [],
                    ),
                ];
            }
        };

        $tool = new ReportList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));
        $page = $tool->execute(1, limit: 1, sort: ReportsPagination::SORT_NAME_ASC, search: 'visitors');
        $cursor = $page['next_cursor'];
        self::assertIsString($cursor);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->execute(1, cursor: $cursor, sort: ReportsPagination::SORT_NAME_ASC, search: 'behaviour');
    }

    public function testListAcceptsCursorWhenEquivalentSearchNormalizationIsUsed(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                return [
                    new ReportSummaryRecord(
                        'VisitsSummary_get',
                        'VisitsSummary',
                        'get',
                        'Overview',
                        'Visitors',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'VisitsSummary_getUsers',
                        'VisitsSummary',
                        'getUsers',
                        'Users',
                        'Visitors',
                        [],
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Behaviour',
                        [],
                    ),
                ];
            }
        };

        $tool = new ReportList($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));

        // The cursor context persists the normalized search key, so a differently
        // spelled but equivalent term must keep the same paginated result set.
        $firstPage = $tool->execute(1, limit: 1, sort: ReportsPagination::SORT_NAME_ASC, search: ' Visits Summary ');
        $cursor = $firstPage['next_cursor'];
        self::assertIsString($cursor);

        $secondPage = $tool->execute(
            1,
            limit: 1,
            cursor: $cursor,
            sort: ReportsPagination::SORT_NAME_ASC,
            search: 'VisitsSummary',
        );

        self::assertCount(1, $secondPage['reports']);
        self::assertFalse($secondPage['has_more']);
        self::assertNull($secondPage['next_cursor']);
        self::assertSame(2, $secondPage['total_rows']);
    }
}
