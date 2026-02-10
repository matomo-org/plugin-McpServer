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
use Piwik\Plugins\McpServer\ApiWrappers\Goals\ListApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryRecord;

/**
 * @group McpServer
 * @group Plugins
 */
class ListApiWrapperTest extends TestCase
{
    public function testGetGoalsForSiteDelegatesToSummaryQueryService(): void
    {
        $expected = [
            new GoalSummaryRecord(3, 1, 'Goal Name', '', 'event_action', false, '0', false),
        ];

        $queryService = new class ($expected) implements GoalSummaryQueryServiceInterface {
            /** @param array<int, GoalSummaryRecord> $records */
            public function __construct(private array $records)
            {
            }

            public function getGoalSummariesForSite(int $idSite): array
            {
                return $this->records;
            }
        };

        $wrapper = new ListApiWrapper($queryService);
        self::assertSame($expected, $wrapper->getGoalsForSite(1));
    }
}
