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
use Piwik\Plugins\McpServer\ApiWrappers\CustomDimensions\GetApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionDetailRecord;

/**
 * @group McpServer
 * @group Plugins
 */
class GetApiWrapperTest extends TestCase
{
    public function testGetDimensionByIdDelegatesToDetailQueryService(): void
    {
        $expected = new DimensionDetailRecord(
            idDimension: 4,
            idSite: 2,
            name: 'Customer Tier',
            index: 1,
            scope: 'visit',
            active: true,
            caseSensitive: false,
            extractions: [
                ['dimension' => 'url', 'pattern' => 'customer=(.*)'],
            ]
        );

        $queryService = new class ($expected) implements DimensionDetailQueryServiceInterface {
            public function __construct(private DimensionDetailRecord $record)
            {
            }

            public function getDimensionDetailForSite(int $idSite, int $idDimension): DimensionDetailRecord
            {
                return $this->record;
            }
        };

        $wrapper = new GetApiWrapper($queryService);
        self::assertSame($expected, $wrapper->getDimensionById(2, 4));
    }
}
