<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use Piwik\Plugins\McpServer\McpServer;
use Piwik\Plugins\McpServer\Session\DbSessionTable;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpServerLifecycleTest extends TestCase
{
    public function testInstallUsesResolvedSessionTable(): void
    {
        $table = $this->createMock(DbSessionTable::class);
        $table->expects(self::once())
            ->method('install');

        $plugin = $this
            ->getMockBuilder(McpServer::class)
            ->onlyMethods(['getSessionTable'])
            ->getMock();
        $plugin->method('getSessionTable')->willReturn($table);

        $plugin->install();
    }

    public function testUninstallUsesResolvedSessionTable(): void
    {
        $table = $this->createMock(DbSessionTable::class);
        $table->expects(self::once())
            ->method('uninstall');

        $plugin = $this
            ->getMockBuilder(McpServer::class)
            ->onlyMethods(['getSessionTable'])
            ->getMock();
        $plugin->method('getSessionTable')->willReturn($table);

        $plugin->uninstall();
    }
}
