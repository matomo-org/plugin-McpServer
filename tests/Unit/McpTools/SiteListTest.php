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
use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\Support\Pagination\SitesPagination;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SiteListTest extends TestCase
{
    public function testListReturnsSortedSummariesFromApiWrapper(): void
    {
        $wrapper = new class () implements SiteSummaryQueryServiceInterface {
            public function getSiteSummariesForList(): array
            {
                return [
                    new SiteSummaryRecord(2, 'Site B', 'https://b.test', 'website'),
                    new SiteSummaryRecord(1, 'Site A', 'https://a.test', 'website'),
                ];
            }

            public function getSiteSummariesForSearch(string $search): array
            {
                return [];
            }
        };

        $actual = (new SiteList($wrapper))->list(limit: 10, sort: SitesPagination::SORT_NAME_ASC);

        self::assertSame([
            'sites' => [
                ['idsite' => 1, 'name' => 'Site A', 'main_url' => 'https://a.test', 'type' => 'website'],
                ['idsite' => 2, 'name' => 'Site B', 'main_url' => 'https://b.test', 'type' => 'website'],
            ],
            'next_cursor' => null,
            'has_more' => false,
        ], $actual);
    }

    public function testListPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements SiteSummaryQueryServiceInterface {
            public function getSiteSummariesForList(): array
            {
                throw new ToolCallException("Site list item is incomplete (missing 'main_url').");
            }

            public function getSiteSummariesForSearch(string $search): array
            {
                return [];
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site list item is incomplete (missing 'main_url').");

        (new SiteList($wrapper))->list();
    }

    public function testListRejectsInvalidCursor(): void
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

        (new SiteList($wrapper))->list(cursor: 'invalid');
    }

    public function testListRejectsCursorSortMismatch(): void
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
                return [];
            }
        };

        $tool = new SiteList($wrapper);
        $page = $tool->list(limit: 1, sort: SitesPagination::SORT_ID_DESC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(cursor: $cursor, sort: SitesPagination::SORT_NAME_ASC);
    }
}
