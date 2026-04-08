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
use Piwik\Plugins\McpServer\Services\Goals\CoreGoalsGateway;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;

/**
 * @group McpServer
 * @group Plugins
 */
class CoreGoalsGatewayTest extends TestCase
{
    public function testGetGoalsReturnsTypedList(): void
    {
        $gateway = new CoreGoalsGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                self::assertSame('Goals.getGoals', $method);
                self::assertSame(['idSite' => '5', 'orderByName' => true], $paramOverride);
                self::assertSame([], $defaultRequest);

                return [
                    ['idgoal' => '2', 'name' => 'Goal Alpha'],
                    ['idgoal' => '3', 'name' => 'Goal Beta'],
                ];
            },
        );
        $result = $gateway->getGoals(5);

        self::assertCount(2, $result);
        self::assertSame('Goal Alpha', $result[0]['name'] ?? null);
        self::assertSame('Goal Beta', $result[1]['name'] ?? null);
    }

    public function testGetGoalsRejectsInvalidTopLevelPayload(): void
    {
        $gateway = new CoreGoalsGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return ['unexpected' => 'shape'];
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Goals data is invalid.');
        $gateway->getGoals(5);
    }

    public function testGetGoalsRejectsInvalidRowPayload(): void
    {
        $gateway = new CoreGoalsGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return [
                    ['idgoal' => '2', 'name' => 'Goal Alpha'],
                    ['invalid-row'],
                ];
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Goals data is invalid.');
        $gateway->getGoals(5);
    }

    public function testGetGoalReturnsTypedRow(): void
    {
        $gateway = new CoreGoalsGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                self::assertSame('Goals.getGoal', $method);
                self::assertSame(['idSite' => 5, 'idGoal' => 3], $paramOverride);
                self::assertSame([], $defaultRequest);

                return ['idgoal' => '3', 'name' => 'Goal Detail'];
            },
        );
        $result = $gateway->getGoal(5, 3);

        self::assertSame('Goal Detail', $result['name'] ?? null);
    }

    public function testGetGoalRejectsInvalidRowPayload(): void
    {
        $gateway = new CoreGoalsGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return ['invalid-row'];
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Goal data is invalid.');
        $gateway->getGoal(5, 3);
    }

    public function testGetGoalMapsMessageBasedAccessFailure(): void
    {
        $gateway = new CoreGoalsGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): mixed {
                throw new \RuntimeException('Missing view access permission');
            },
        );

        $this->expectException(AccessDeniedLikeException::class);
        $this->expectExceptionMessage('No access to this resource.');
        $gateway->getGoal(5, 3);
    }
}
