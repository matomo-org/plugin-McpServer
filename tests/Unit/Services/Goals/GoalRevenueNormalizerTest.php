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
use Piwik\Plugins\McpServer\Services\Goals\GoalRevenueNormalizer;

/**
 * @group McpServer
 * @group Plugins
 */
class GoalRevenueNormalizerTest extends TestCase
{
    public function testNormalizeRevenueReturnsStringValueAsIs(): void
    {
        $goal = ['revenue' => '9.99'];

        self::assertSame('9.99', GoalRevenueNormalizer::normalizeRevenue($goal, 'Goal data'));
    }

    public function testNormalizeRevenueCastsIntToString(): void
    {
        $goal = ['revenue' => 12];

        self::assertSame('12', GoalRevenueNormalizer::normalizeRevenue($goal, 'Goal data'));
    }

    public function testNormalizeRevenueCastsFloatToString(): void
    {
        $goal = ['revenue' => 12.5];

        self::assertSame('12.5', GoalRevenueNormalizer::normalizeRevenue($goal, 'Goal data'));
    }

    public function testNormalizeRevenueThrowsWhenRevenueIsMissing(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal data is incomplete (missing 'revenue').");

        GoalRevenueNormalizer::normalizeRevenue([], 'Goal data');
    }

    public function testNormalizeRevenueThrowsWhenRevenueIsNull(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal data is incomplete (missing 'revenue').");

        GoalRevenueNormalizer::normalizeRevenue(['revenue' => null], 'Goal data');
    }

    public function testNormalizeRevenueThrowsWhenRevenueHasInvalidType(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal data is invalid (field 'revenue').");

        GoalRevenueNormalizer::normalizeRevenue(['revenue' => ['unexpected']], 'Goal data');
    }
}
