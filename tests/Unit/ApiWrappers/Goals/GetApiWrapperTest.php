<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\ApiWrappers\Goals;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\ApiWrappers\Goals\GetApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalDetailRecord;

/**
 * @group McpServer
 * @group Plugins
 */
class GetApiWrapperTest extends TestCase
{
    public function testGetGoalByIdDelegatesToDetailQueryService(): void
    {
        $expected = new GoalDetailRecord(
            idGoal: 4,
            idSite: 2,
            name: 'Goal Name',
            description: '',
            matchAttribute: 'event_action',
            allowMultiple: false,
            revenue: '0',
            eventValueAsRevenue: false,
            pattern: 'evt-alpha',
            patternType: 'exact',
            caseSensitive: true
        );

        $queryService = new class ($expected) implements GoalDetailQueryServiceInterface {
            public function __construct(private GoalDetailRecord $record)
            {
            }

            public function getGoalDetailForSite(int $idSite, int $idGoal): GoalDetailRecord
            {
                return $this->record;
            }
        };

        $wrapper = new GetApiWrapper($queryService);
        self::assertSame($expected, $wrapper->getGoalById(2, 4));
    }
}
