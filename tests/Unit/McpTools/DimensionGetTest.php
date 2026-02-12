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
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionDetailRecord;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\McpTools\DimensionGet;

/**
 * @group McpServer
 * @group Plugins
 */
class DimensionGetTest extends TestCase
{
    public function testGetReturnsRecordFromApiWrapper(): void
    {
        $wrapper = new class () implements DimensionDetailQueryServiceInterface {
            public function getDimensionDetailForSite(int $idSite, int $idDimension): DimensionDetailRecord
            {
                return new DimensionDetailRecord(
                    idDimension: $idDimension,
                    idSite: $idSite,
                    name: 'Customer Tier',
                    index: 2,
                    scope: 'visit',
                    active: true,
                    caseSensitive: false,
                    extractions: []
                );
            }
        };

        $actual = (new DimensionGet($wrapper))->get(4, 7);

        self::assertSame([
            'iddimension' => 7,
            'idsite' => 4,
            'name' => 'Customer Tier',
            'index' => 2,
            'scope' => 'visit',
            'active' => true,
            'case_sensitive' => false,
            'extractions' => [],
        ], $actual);
    }

    public function testGetPassesArgumentsToApiWrapper(): void
    {
        $wrapper = new class () implements DimensionDetailQueryServiceInterface {
            /** @var array<string, int> */
            public array $captured = [];

            public function getDimensionDetailForSite(int $idSite, int $idDimension): DimensionDetailRecord
            {
                $this->captured = [
                    'idSite' => $idSite,
                    'idDimension' => $idDimension,
                ];

                return new DimensionDetailRecord(
                    idDimension: $idDimension,
                    idSite: $idSite,
                    name: 'Dimension Name',
                    index: 1,
                    scope: 'action',
                    active: false,
                    caseSensitive: true,
                    extractions: [['dimension' => 'url', 'pattern' => '(.*)']]
                );
            }
        };

        (new DimensionGet($wrapper))->get(9, 3);

        self::assertSame(['idSite' => 9, 'idDimension' => 3], $wrapper->captured);
    }

    public function testGetPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements DimensionDetailQueryServiceInterface {
            public function getDimensionDetailForSite(int $idSite, int $idDimension): DimensionDetailRecord
            {
                throw new ToolCallException("Dimension data is incomplete (missing 'name').");
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Dimension data is incomplete (missing 'name').");

        (new DimensionGet($wrapper))->get(4, 7);
    }
}
