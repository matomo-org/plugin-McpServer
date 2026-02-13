<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use Piwik\Plugins\McpServer\Session\DbSessionStore;
use Piwik\Plugins\McpServer\Tasks;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class TasksTest extends TestCase
{
    public function testCleanupExpiredSessionsRunsGarbageCollectionOnResolvedStore(): void
    {
        $store = $this
            ->getMockBuilder(DbSessionStore::class)
            ->onlyMethods(['gc'])
            ->getMock();

        $store->expects(self::once())
            ->method('gc')
            ->willReturn([]);

        $tasks = $this
            ->getMockBuilder(Tasks::class)
            ->onlyMethods(['getSessionStore'])
            ->getMock();
        $tasks->method('getSessionStore')->willReturn($store);

        $tasks->cleanupExpiredSessions();
    }
}
