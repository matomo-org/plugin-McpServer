<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\ApiWrappers\CustomDimensions;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\ApiWrappers\CustomDimensions\ListApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionSummaryRecord;

/**
 * @group McpServer
 * @group Plugins
 */
class ListApiWrapperTest extends TestCase
{
    public function testGetDimensionsForSiteDelegatesToSummaryQueryService(): void
    {
        $expected = [
            new DimensionSummaryRecord(3, 'Dimension Name', 'visit'),
        ];

        $queryService = new class ($expected) implements DimensionSummaryQueryServiceInterface {
            /** @param array<int, DimensionSummaryRecord> $records */
            public function __construct(private array $records)
            {
            }

            public function getDimensionSummariesForSite(int $idSite): array
            {
                return $this->records;
            }
        };

        $wrapper = new ListApiWrapper($queryService);
        self::assertSame($expected, $wrapper->getDimensionsForSite(1));
    }
}
