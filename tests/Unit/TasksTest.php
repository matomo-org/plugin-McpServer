<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Session\DbSessionStore;
use Piwik\Plugins\McpServer\Tasks;

/**
 * @group McpServer
 * @group Plugins
 */
class TasksTest extends TestCase
{
    public function testCleanupExpiredSessionsRunsGarbageCollectionOnInjectedStore(): void
    {
        $store = $this
            ->getMockBuilder(DbSessionStore::class)
            ->onlyMethods(['gc'])
            ->getMock();

        $store->expects(self::once())
            ->method('gc')
            ->willReturn([]);

        $tasks = new Tasks($store);

        $tasks->cleanupExpiredSessions();
    }
}
