<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Piwik\Plugins\McpServer\Contracts\McpTool;

/**
 * Resolves the set of MCP tools exposed by the server.
 *
 * The default implementation {@see McpToolsProvider} lists the McpServer
 * built-in tools and lets other plugins extend or restrict the set via the
 * {@hook McpServer.addTools} and {@hook McpServer.filterTools} events.
 */
interface McpToolsProviderInterface
{
    /**
     * Return all MCP tools that should be considered for registration.
     *
     * @return list<McpTool>
     */
    public function getAllTools(): array;
}
