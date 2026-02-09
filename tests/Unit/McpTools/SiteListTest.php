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
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\ListApiWrapperInterface;
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\SiteSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SiteListTest extends TestCase
{
    public function testListReturnsSortedSummariesFromApiWrapper(): void
    {
        $wrapper = new class () implements ListApiWrapperInterface {
            public function getSitesWithViewAccess(): array
            {
                return [
                    new SiteSummaryRecord(2, 'Site B', 'https://b.test', 'website'),
                    new SiteSummaryRecord(1, 'Site A', 'https://a.test', 'website'),
                ];
            }
        };

        $actual = (new SiteList($wrapper))->list(limit: 10, sort: SiteList::SORT_NAME_ASC);

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
        $wrapper = new class () implements ListApiWrapperInterface {
            public function getSitesWithViewAccess(): array
            {
                throw new ToolCallException("Site list item is incomplete (missing 'main_url').");
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site list item is incomplete (missing 'main_url').");

        (new SiteList($wrapper))->list();
    }

    public function testListRejectsInvalidCursor(): void
    {
        $wrapper = new class () implements ListApiWrapperInterface {
            public function getSitesWithViewAccess(): array
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
        $wrapper = new class () implements ListApiWrapperInterface {
            public function getSitesWithViewAccess(): array
            {
                return [
                    new SiteSummaryRecord(1, 'Site A', 'https://a.test', 'website'),
                    new SiteSummaryRecord(2, 'Site B', 'https://b.test', 'website'),
                ];
            }
        };

        $tool = new SiteList($wrapper);
        $page = $tool->list(limit: 1, sort: SiteList::SORT_ID_DESC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(cursor: $cursor, sort: SiteList::SORT_NAME_ASC);
    }
}
