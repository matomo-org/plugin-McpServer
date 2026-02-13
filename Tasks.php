<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Piwik\Plugins\McpServer\Session\DbSessionStore;

class Tasks extends \Piwik\Plugin\Tasks
{
    public function __construct(private DbSessionStore $sessionStore)
    {
    }

    public function schedule(): void
    {
        $this->daily('cleanupExpiredSessions');
    }

    public function cleanupExpiredSessions(): void
    {
        $this->sessionStore->gc();
    }
}
