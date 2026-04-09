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
use Piwik\Plugins\McpServer\Support\Access\McpAccessLevel;

/**
 * @group McpServer
 * @group Plugins
 */
class McpAccessLevelTest extends TestCase
{
    public function testNormalizeMaximumAllowedFallsBackToUnlimitedForUnknownValues(): void
    {
        self::assertSame(McpAccessLevel::UNLIMITED, McpAccessLevel::normalizeMaximumAllowed('unknown'));
        self::assertSame(McpAccessLevel::UNLIMITED, McpAccessLevel::normalizeMaximumAllowed(null));
    }

    public function testExceedsMaximumAllowedUsesPrivilegeOrdering(): void
    {
        self::assertFalse(McpAccessLevel::exceedsMaximumAllowed(McpAccessLevel::VIEW, McpAccessLevel::VIEW));
        self::assertFalse(McpAccessLevel::exceedsMaximumAllowed(McpAccessLevel::ADMIN, McpAccessLevel::UNLIMITED));
        self::assertTrue(McpAccessLevel::exceedsMaximumAllowed(McpAccessLevel::WRITE, McpAccessLevel::VIEW));
        self::assertTrue(McpAccessLevel::exceedsMaximumAllowed(McpAccessLevel::SUPERUSER, McpAccessLevel::ADMIN));
    }

    public function testCreateTooHighPrivilegeMessageFormatsMaximumLevel(): void
    {
        self::assertSame(
            'Authenticated MCP access has too high privilege level. Maximum of Write access level is allowed.',
            McpAccessLevel::createTooHighPrivilegeMessage(McpAccessLevel::WRITE),
        );
    }
}
