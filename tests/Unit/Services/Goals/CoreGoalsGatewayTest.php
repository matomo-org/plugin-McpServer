<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Goals;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\Services\Goals\CoreGoalsGateway;

/**
 * @group McpServer
 * @group Plugins
 */
class CoreGoalsGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        GoalsApi::unsetInstance();
        parent::tearDown();
    }

    public function testGetGoalsReturnsTypedList(): void
    {
        $api = $this->createMock(GoalsApi::class);
        $api->expects(self::once())
            ->method('getGoals')
            ->with('5', true)
            ->willReturn([
                ['idgoal' => '2', 'name' => 'Goal Alpha'],
                ['idgoal' => '3', 'name' => 'Goal Beta'],
            ]);
        GoalsApi::setSingletonInstance($api);

        $gateway = new CoreGoalsGateway();
        $result = $gateway->getGoals(5);

        self::assertCount(2, $result);
        self::assertSame('Goal Alpha', $result[0]['name'] ?? null);
        self::assertSame('Goal Beta', $result[1]['name'] ?? null);
    }

    public function testGetGoalsRejectsInvalidTopLevelPayload(): void
    {
        $api = $this->createMock(GoalsApi::class);
        $api->expects(self::once())
            ->method('getGoals')
            ->willReturn(['unexpected' => 'shape']);
        GoalsApi::setSingletonInstance($api);

        $gateway = new CoreGoalsGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Goals data is invalid.');
        $gateway->getGoals(5);
    }

    public function testGetGoalsRejectsInvalidRowPayload(): void
    {
        $api = $this->createMock(GoalsApi::class);
        $api->expects(self::once())
            ->method('getGoals')
            ->willReturn([
                ['idgoal' => '2', 'name' => 'Goal Alpha'],
                ['invalid-row'],
            ]);
        GoalsApi::setSingletonInstance($api);

        $gateway = new CoreGoalsGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Goals data is invalid.');
        $gateway->getGoals(5);
    }

    public function testGetGoalReturnsTypedRow(): void
    {
        $api = $this->createMock(GoalsApi::class);
        $api->expects(self::once())
            ->method('getGoal')
            ->with(5, 3)
            ->willReturn(['idgoal' => '3', 'name' => 'Goal Detail']);
        GoalsApi::setSingletonInstance($api);

        $gateway = new CoreGoalsGateway();
        $result = $gateway->getGoal(5, 3);

        self::assertSame('Goal Detail', $result['name'] ?? null);
    }

    public function testGetGoalRejectsInvalidRowPayload(): void
    {
        $api = $this->createMock(GoalsApi::class);
        $api->expects(self::once())
            ->method('getGoal')
            ->willReturn(['invalid-row']);
        GoalsApi::setSingletonInstance($api);

        $gateway = new CoreGoalsGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Goal data is invalid.');
        $gateway->getGoal(5, 3);
    }
}
