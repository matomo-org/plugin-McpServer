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

    public function testListPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements ReportSummaryQueryServiceInterface {
            public function getReportSummariesForSite(int $idSite): array
            {
                throw new ToolCallException("Report list item is incomplete (missing 'name').");
            }
        };

        $this->expectException(ToolCallException::class);
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

        $this->expectException(ToolCallException::class);
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
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
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
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->execute(2, cursor: $cursor, sort: ReportsPagination::SORT_NAME_ASC);
    }
}
