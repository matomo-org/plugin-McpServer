<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Access;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;

/**
 * @group McpServer
 * @group Plugins
 */
class RawApiAccessModeTest extends TestCase
{
    public function testNormalizeFallsBackToDefaultForInvalidValues(): void
    {
        self::assertSame(RawApiAccessMode::NONE, RawApiAccessMode::normalize(null));
        self::assertSame(RawApiAccessMode::NONE, RawApiAccessMode::normalize([]));
        self::assertSame(RawApiAccessMode::NONE, RawApiAccessMode::normalize('invalid'));
    }

    public function testNormalizeAcceptsSupportedValuesCaseInsensitively(): void
    {
        self::assertSame(RawApiAccessMode::NONE, RawApiAccessMode::normalize(' NONE '));
        self::assertSame(RawApiAccessMode::READ, RawApiAccessMode::normalize('Read'));
        self::assertSame(RawApiAccessMode::FULL, RawApiAccessMode::normalize('FULL'));
    }

    public function testAllowsMethodActionRespectsReadAndFullModes(): void
    {
        self::assertTrue(RawApiAccessMode::allowsMethodAction(RawApiAccessMode::FULL, 'deleteUser'));

        self::assertTrue(RawApiAccessMode::allowsMethodAction(RawApiAccessMode::READ, 'getUsers'));
        self::assertTrue(RawApiAccessMode::allowsMethodAction(RawApiAccessMode::READ, 'isGoalEnabled'));
        self::assertFalse(RawApiAccessMode::allowsMethodAction(RawApiAccessMode::READ, 'addUser'));
        self::assertFalse(RawApiAccessMode::allowsMethodAction(RawApiAccessMode::NONE, 'getUsers'));
    }
}
