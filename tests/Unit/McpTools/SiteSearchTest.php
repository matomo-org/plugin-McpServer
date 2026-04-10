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
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Sites\SiteSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\McpTools\SiteSearch;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\SitesPagination;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @group McpServer
 * @group Plugins
 */
class SiteSearchTest extends TestCase
{
    public function testSearchReturnsSortedSummariesFromApiWrapper(): void
    {
        $wrapper = new class () implements SiteSummaryQueryServiceInterface {
            public function getSiteSummariesForList(): array
            {
                return [];
            }

            public function getSiteSummariesForSearch(string $search): array
            {
                return [
                    new SiteSummaryRecord(2, 'Site B', 'https://b.test', 'website'),
                    new SiteSummaryRecord(1, 'Site A', 'https://a.test', 'website'),
                ];
            }
        };

        $actual = (new SiteSearch($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))->search(
            'site',
            limit: 10,
            sort: SitesPagination::SORT_NAME_ASC,
        );

        self::assertSame([
            'sites' => [
                ['idsite' => 1, 'name' => 'Site A', 'main_url' => 'https://a.test', 'type' => 'website'],
                ['idsite' => 2, 'name' => 'Site B', 'main_url' => 'https://b.test', 'type' => 'website'],
            ],
            'next_cursor' => null,
            'has_more' => false,
            'total_rows' => 2,
        ], $actual);
    }

    public function testSearchPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements SiteSummaryQueryServiceInterface {
            public function getSiteSummariesForList(): array
            {
                return [];
            }

            public function getSiteSummariesForSearch(string $search): array
            {
                throw new ToolCallException("Site search item is incomplete (missing 'main_url').");
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site search item is incomplete (missing 'main_url').");

        (new SiteSearch($wrapper, new PaginatedCollectionResponder(new CursorPaginator())))->search('site');
    }

    public function testSearchRejectsInvalidCursor(): void
    {
        $wrapper = new class () implements SiteSummaryQueryServiceInterface {
            public function getSiteSummariesForList(): array
            {
                return [];
            }

            public function getSiteSummariesForSearch(string $search): array
            {
                return [];
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        (new SiteSearch(
            $wrapper,
            new PaginatedCollectionResponder(new CursorPaginator()),
        ))->search('site', cursor: 'invalid');
    }

    public function testSearchRejectsCursorSortMismatch(): void
    {
        $wrapper = new class () implements SiteSummaryQueryServiceInterface {
            public function getSiteSummariesForList(): array
            {
                return [];
            }

            public function getSiteSummariesForSearch(string $search): array
            {
                return [
                    new SiteSummaryRecord(1, 'Site A', 'https://a.test', 'website'),
                    new SiteSummaryRecord(2, 'Site B', 'https://b.test', 'website'),
                ];
            }
        };

        $tool = new SiteSearch($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));
        $page = $tool->search('site', limit: 1, sort: SitesPagination::SORT_ID_DESC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->search('site', cursor: $cursor, sort: SitesPagination::SORT_NAME_ASC);
    }

    public function testSearchRejectsCursorSearchMismatch(): void
    {
        $wrapper = new class () implements SiteSummaryQueryServiceInterface {
            public function getSiteSummariesForList(): array
            {
                return [];
            }

            public function getSiteSummariesForSearch(string $search): array
            {
                return [
                    new SiteSummaryRecord(1, 'Site A', 'https://a.test', 'website'),
                    new SiteSummaryRecord(2, 'Site B', 'https://b.test', 'website'),
                ];
            }
        };

        $tool = new SiteSearch($wrapper, new PaginatedCollectionResponder(new CursorPaginator()));
        $page = $tool->search('alpha', limit: 1, sort: SitesPagination::SORT_NAME_ASC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->search('beta', cursor: $cursor, sort: SitesPagination::SORT_NAME_ASC);
    }

    public function testSearchRejectsCursorFromSiteListContext(): void
    {
        $wrapper = new class () implements SiteSummaryQueryServiceInterface {
            public function getSiteSummariesForList(): array
            {
                return [
                    new SiteSummaryRecord(1, 'Site A', 'https://a.test', 'website'),
                    new SiteSummaryRecord(2, 'Site B', 'https://b.test', 'website'),
                ];
            }

            public function getSiteSummariesForSearch(string $search): array
            {
                return [
                    new SiteSummaryRecord(1, 'Site A', 'https://a.test', 'website'),
                    new SiteSummaryRecord(2, 'Site B', 'https://b.test', 'website'),
                ];
            }
        };

        $responder = new PaginatedCollectionResponder(new CursorPaginator());
        $listTool = new SiteList($wrapper, $responder);
        $searchTool = new SiteSearch($wrapper, $responder);

        $page = $listTool->list(limit: 1, sort: SitesPagination::SORT_NAME_ASC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $searchTool->search('site', cursor: $cursor, sort: SitesPagination::SORT_NAME_ASC);
    }
}
