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
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Goals\GoalDetailRecord;
use Piwik\Plugins\McpServer\McpTools\GoalGet;

/**
 * @group McpServer
 * @group Plugins
 */
class GoalGetTest extends TestCase
{
    public function testGetReturnsRecordFromApiWrapper(): void
    {
        $wrapper = new class () implements GoalDetailQueryServiceInterface {
            public function getGoalDetailForSite(int $idSite, int $idGoal): GoalDetailRecord
            {
                return new GoalDetailRecord(
                    idGoal: $idGoal,
                    idSite: $idSite,
                    name: 'Goal Name',
                    description: 'Goal Description',
                    matchAttribute: 'event_action',
                    allowMultiple: true,
                    revenue: '12.5',
                    eventValueAsRevenue: false,
                    pattern: 'evt-alpha',
                    patternType: 'exact',
                    caseSensitive: false
                );
            }
        };

        $actual = (new GoalGet($wrapper))->get(4, 7);

        self::assertSame([
            'idgoal' => 7,
            'idsite' => 4,
            'name' => 'Goal Name',
            'description' => 'Goal Description',
            'match_attribute' => 'event_action',
            'allow_multiple' => true,
            'revenue' => '12.5',
            'event_value_as_revenue' => false,
            'pattern' => 'evt-alpha',
            'pattern_type' => 'exact',
            'case_sensitive' => false,
        ], $actual);
    }

    public function testGetPassesArgumentsToApiWrapper(): void
    {
        $wrapper = new class () implements GoalDetailQueryServiceInterface {
            /** @var array<string, int> */
            public array $captured = [];

            public function getGoalDetailForSite(int $idSite, int $idGoal): GoalDetailRecord
            {
                $this->captured = [
                    'idSite' => $idSite,
                    'idGoal' => $idGoal,
                ];

                return new GoalDetailRecord(
                    idGoal: $idGoal,
                    idSite: $idSite,
                    name: 'Goal Name',
                    description: '',
                    matchAttribute: 'manually',
                    allowMultiple: false,
                    revenue: '0',
                    eventValueAsRevenue: false,
                    pattern: null,
                    patternType: null,
                    caseSensitive: null
                );
            }
        };

        (new GoalGet($wrapper))->get(9, 3);

        self::assertSame(['idSite' => 9, 'idGoal' => 3], $wrapper->captured);
    }

    public function testGetPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements GoalDetailQueryServiceInterface {
            public function getGoalDetailForSite(int $idSite, int $idGoal): GoalDetailRecord
            {
                throw new ToolCallException("Goal data is incomplete (missing 'name').");
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal data is incomplete (missing 'name').");

        (new GoalGet($wrapper))->get(4, 7);
    }
}
