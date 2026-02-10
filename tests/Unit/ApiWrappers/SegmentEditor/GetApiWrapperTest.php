<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\ApiWrappers\SegmentEditor;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\ApiWrappers\SegmentEditor\GetApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentDetailRecord;

/**
 * @group McpServer
 * @group Plugins
 */
class GetApiWrapperTest extends TestCase
{
    public function testGetSegmentBySelectorResolvesByIdSegment(): void
    {
        $wrapper = new GetApiWrapper($this->makeQueryService([
            new SegmentDetailRecord(10, 'Segment A', 'countryCode==de', 3, false, true, 'alice'),
            new SegmentDetailRecord(11, 'Segment B', 'countryCode==fr', 3, true, false, 'bob'),
        ]));

        $actual = $wrapper->getSegmentBySelector(idSite: 3, idSegment: 11);

        self::assertSame(11, $actual->idSegment);
        self::assertSame('Segment B', $actual->name);
    }

    public function testGetSegmentBySelectorResolvesByName(): void
    {
        $wrapper = new GetApiWrapper($this->makeQueryService([
            new SegmentDetailRecord(10, 'Segment A', 'countryCode==de', 3, false, true, 'alice'),
        ]));

        $actual = $wrapper->getSegmentBySelector(idSite: 3, name: 'Segment A');

        self::assertSame(10, $actual->idSegment);
    }

    public function testGetSegmentBySelectorResolvesByDefinition(): void
    {
        $wrapper = new GetApiWrapper($this->makeQueryService([
            new SegmentDetailRecord(10, 'Segment A', 'countryCode==de', 3, false, true, 'alice'),
        ]));

        $actual = $wrapper->getSegmentBySelector(idSite: 3, definition: 'countryCode==de');

        self::assertSame(10, $actual->idSegment);
    }

    public function testGetSegmentBySelectorThrowsWhenNoMatch(): void
    {
        $wrapper = new GetApiWrapper($this->makeQueryService([]));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Segment not found.');

        $wrapper->getSegmentBySelector(idSite: 3, idSegment: 99);
    }

    public function testGetSegmentBySelectorThrowsWhenNameIsAmbiguous(): void
    {
        $wrapper = new GetApiWrapper($this->makeQueryService([
            new SegmentDetailRecord(10, 'Segment A', 'countryCode==de', 3, false, true, 'alice'),
            new SegmentDetailRecord(11, 'Segment A', 'countryCode==fr', 3, true, false, 'bob'),
        ]));

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Multiple segments matched. Provide idSegment.');

        $wrapper->getSegmentBySelector(idSite: 3, name: 'Segment A');
    }

    /**
     * @param array<int, SegmentDetailRecord> $records
     */
    private function makeQueryService(array $records): SegmentDetailQueryServiceInterface
    {
        return new class ($records) implements SegmentDetailQueryServiceInterface {
            /** @param array<int, SegmentDetailRecord> $records */
            public function __construct(private array $records)
            {
            }

            public function getSegmentDetailsForSite(int $idSite): array
            {
                return $this->records;
            }
        };
    }
}
