<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Piwik\Plugin;
use Piwik\Plugins\McpServer\Session\DbSessionTable;

class McpServer extends Plugin
{
    /**
     * @return array<string, string>
     */
    public function registerEvents(): array
    {
        return [
            'AssetManager.getStylesheetFiles' => 'getStylesheetFiles',
        ];
    }

    /**
     * @param array<string> $stylesheets
     */
    public function getStylesheetFiles(array &$stylesheets): void
    {
        $stylesheets[] = 'plugins/McpServer/stylesheets/mcpServer.less';
    }

    public function install(): void
    {
        (new DbSessionTable())->install();
    }

    public function uninstall(): void
    {
        (new DbSessionTable())->uninstall();
    }
}
