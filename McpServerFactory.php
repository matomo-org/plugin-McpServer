<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Matomo\Dependencies\McpServer\Mcp\Schema\ServerCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Server;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\FileSessionStore;
use Piwik\Plugin\Manager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

final class McpServerFactory
{
    public function createServer(LoggerInterface $logger, string $tmpPath, ContainerInterface $container): Server
    {
        $version = (string) Manager::getInstance()->getVersion('McpServer');

        return Server::builder()
            ->setServerInfo('Matomo MCP Server', $version)
            ->setLogger($logger)
            ->setSession(new FileSessionStore($tmpPath . '/mcp_sessions'))
            ->setContainer($container)
            ->setDiscovery(__DIR__, ['McpTools'])
            ->setCapabilities(new ServerCapabilities(
                tools: true,
                // Use null to avoid advertising listChanged capabilities we don't implement.
                toolsListChanged: null,
                resources: false,
                resourcesSubscribe: null,
                resourcesListChanged: null,
                prompts: false,
                promptsListChanged: null,
                logging: false,
                completions: false,
            ))
            ->build();
    }
}
