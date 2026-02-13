<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Piwik\Container\StaticContainer;
use Piwik\Plugin;
use Piwik\Plugins\McpServer\Session\DbSessionTable;

class McpServer extends Plugin
{
    public function install(): void
    {
        $this->getSessionTable()->install();
    }

    public function uninstall(): void
    {
        $this->getSessionTable()->uninstall();
    }

    protected function getSessionTable(): DbSessionTable
    {
        return StaticContainer::get(DbSessionTable::class);
    }
}
