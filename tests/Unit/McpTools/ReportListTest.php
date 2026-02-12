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
use Piwik\Plugins\McpServer\Support\Pagination\ReportsPagination;

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
                        []
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Actions',
                        ['foo' => 'bar']
                    ),
                ];
            }
        };

        $actual = (new ReportList($wrapper))->list(
            1,
            limit: 10,
            sort: ReportsPagination::SORT_CATEGORY_ASC
        );

        self::assertSame([
            'reports' => [
                [
                    'uniqueId' => 'Actions_getPageUrls',
                    'module' => 'Actions',
                    'action' => 'getPageUrls',
                    'name' => 'Page URLs',
                    'category' => 'Actions',
                    'parameters' => ['foo' => 'bar'],
                ],
                [
                    'uniqueId' => 'VisitsSummary_get',
                    'module' => 'VisitsSummary',
                    'action' => 'get',
                    'name' => 'Overview',
                    'category' => 'Visits Summary',
                    'parameters' => [],
                ],
            ],
            'next_cursor' => null,
            'has_more' => false,
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

        (new ReportList($wrapper))->list(1);
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

        (new ReportList($wrapper))->list(1, cursor: 'invalid');
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
                        []
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Actions',
                        []
                    ),
                ];
            }
        };

        $tool = new ReportList($wrapper);
        $page = $tool->list(1, limit: 1, sort: ReportsPagination::SORT_NAME_DESC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(1, cursor: $cursor, sort: ReportsPagination::SORT_NAME_ASC);
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
                        []
                    ),
                    new ReportSummaryRecord(
                        'Actions_getPageUrls',
                        'Actions',
                        'getPageUrls',
                        'Page URLs',
                        'Actions',
                        []
                    ),
                ];
            }
        };

        $tool = new ReportList($wrapper);
        $page = $tool->list(1, limit: 1, sort: ReportsPagination::SORT_NAME_ASC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(2, cursor: $cursor, sort: ReportsPagination::SORT_NAME_ASC);
    }
}
