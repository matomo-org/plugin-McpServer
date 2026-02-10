<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\ApiWrappers\SegmentEditor;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\ApiWrappers\SegmentEditor\ListApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentSummaryRecord;

/**
 * @group McpServer
 * @group Plugins
 */
class ListApiWrapperTest extends TestCase
{
    public function testGetSegmentsForSiteDelegatesToSummaryQueryService(): void
    {
        $expected = [
            new SegmentSummaryRecord(3, 'Segment Name', 'countryCode==de', null),
        ];

        $queryService = new class ($expected) implements SegmentSummaryQueryServiceInterface {
            /** @param array<int, SegmentSummaryRecord> $records */
            public function __construct(private array $records)
            {
            }

            public function getSegmentSummariesForSite(int $idSite): array
            {
                return $this->records;
            }
        };

        $wrapper = new ListApiWrapper($queryService);
        self::assertSame($expected, $wrapper->getSegmentsForSite(1));
    }
}
