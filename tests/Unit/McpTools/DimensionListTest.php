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
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\McpTools\DimensionList;
use Piwik\Plugins\McpServer\Support\Pagination\DimensionsPagination;

/**
 * @group McpServer
 * @group Plugins
 */
class DimensionListTest extends TestCase
{
    public function testListReturnsSortedSummariesFromApiWrapper(): void
    {
        $wrapper = new class () implements DimensionSummaryQueryServiceInterface {
            public function getDimensionSummariesForSite(int $idSite): array
            {
                return [
                    new DimensionSummaryRecord(8, 'Zeta Dimension', 'action'),
                    new DimensionSummaryRecord(7, 'Alpha Dimension', 'visit'),
                ];
            }
        };

        $actual = (new DimensionList($wrapper))->list(
            1,
            limit: 10,
            sort: DimensionsPagination::SORT_NAME_ASC
        );

        self::assertSame([
            'dimensions' => [
                [
                    'iddimension' => 7,
                    'name' => 'Alpha Dimension',
                    'scope' => 'visit',
                ],
                [
                    'iddimension' => 8,
                    'name' => 'Zeta Dimension',
                    'scope' => 'action',
                ],
            ],
            'next_cursor' => null,
            'has_more' => false,
        ], $actual);
    }

    public function testListPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements DimensionSummaryQueryServiceInterface {
            public function getDimensionSummariesForSite(int $idSite): array
            {
                throw new ToolCallException("Dimension list item is incomplete (missing 'name').");
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Dimension list item is incomplete (missing 'name').");

        (new DimensionList($wrapper))->list(1);
    }

    public function testListRejectsInvalidCursor(): void
    {
        $wrapper = new class () implements DimensionSummaryQueryServiceInterface {
            public function getDimensionSummariesForSite(int $idSite): array
            {
                return [];
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        (new DimensionList($wrapper))->list(1, cursor: 'invalid');
    }

    public function testListRejectsCursorSortMismatch(): void
    {
        $wrapper = new class () implements DimensionSummaryQueryServiceInterface {
            public function getDimensionSummariesForSite(int $idSite): array
            {
                return [
                    new DimensionSummaryRecord(2, 'Dimension A', 'visit'),
                    new DimensionSummaryRecord(1, 'Dimension B', 'action'),
                ];
            }
        };

        $tool = new DimensionList($wrapper);
        $page = $tool->list(1, limit: 1, sort: DimensionsPagination::SORT_ID_DESC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(1, cursor: $cursor, sort: DimensionsPagination::SORT_NAME_ASC);
    }

    public function testListRejectsCursorFromDifferentSiteContext(): void
    {
        $wrapper = new class () implements DimensionSummaryQueryServiceInterface {
            public function getDimensionSummariesForSite(int $idSite): array
            {
                return [
                    new DimensionSummaryRecord(1, 'Dimension A', 'visit'),
                    new DimensionSummaryRecord(2, 'Dimension B', 'action'),
                ];
            }
        };

        $tool = new DimensionList($wrapper);
        $page = $tool->list(1, limit: 1, sort: DimensionsPagination::SORT_ID_ASC);
        $cursor = $page['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(2, cursor: $cursor, sort: DimensionsPagination::SORT_ID_ASC);
    }
}
