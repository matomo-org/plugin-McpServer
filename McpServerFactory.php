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
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\CallToolHandler;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ReferenceHandler;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Piwik\Config;
use Piwik\Plugin\Manager;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Server\Handler\Request\ObservedCallToolHandler;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

final class McpServerFactory
{
    private const DEFAULT_TOOL_CALL_LOG_LEVEL = 'DEBUG';

    public function __construct(
        private LoggerInterface $logger,
        private SessionStoreInterface $sessionStore,
        private ContainerInterface $container,
        private ToolCallParameterFormatter $toolCallParameterFormatter
    ) {
    }

    public function createServer(): Server
    {
        $version = (string) Manager::getInstance()->getVersion('McpServer');

        $registry = new Registry(logger: new NullLogger());

        $builder = Server::builder()
            ->setServerInfo('Matomo MCP Server', $version)
            ->setLogger(new NullLogger())
            ->setRegistry($registry)
            ->setSession($this->sessionStore)
            ->setContainer($this->container)
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
            ));

        if ($this->isToolCallLoggingEnabled()) {
            $referenceHandler = new ReferenceHandler($this->container);
            $callToolHandler = new CallToolHandler($registry, $referenceHandler, new NullLogger());
            $builder->addRequestHandler(new ObservedCallToolHandler(
                $callToolHandler,
                $this->logger,
                $this->toolCallParameterFormatter,
                $this->isFullParameterLoggingEnabled(),
                $this->resolveToolCallLogLevel()
            ));
        }

        return $builder->build();
    }

    private function isToolCallLoggingEnabled(): bool
    {
        $config = $this->getMcpServerConfig();
        if (!array_key_exists('log_tool_calls', $config)) {
            return false;
        }

        return $config['log_tool_calls'] == 1;
    }

    private function isFullParameterLoggingEnabled(): bool
    {
        $config = $this->getMcpServerConfig();
        if (!array_key_exists('log_tool_call_parameters_full', $config)) {
            return false;
        }

        return $config['log_tool_call_parameters_full'] == 1;
    }

    private function resolveToolCallLogLevel(): string
    {
        $config = $this->getMcpServerConfig();
        $configuredLevel = $config['log_tool_call_level'] ?? null;

        if (!is_scalar($configuredLevel)) {
            return self::DEFAULT_TOOL_CALL_LOG_LEVEL;
        }

        $normalizedLevel = strtoupper(trim((string) $configuredLevel));
        $validLevels = ['ERROR', 'WARN', 'WARNING', 'INFO', 'DEBUG', 'VERBOSE'];
        if (!in_array($normalizedLevel, $validLevels, true)) {
            return self::DEFAULT_TOOL_CALL_LOG_LEVEL;
        }

        return $normalizedLevel;
    }

    /**
     * @return array<string, mixed>
     */
    private function getMcpServerConfig(): array
    {
        $config = Config::getInstance()->McpServer ?? [];

        return is_array($config) ? $config : [];
    }
}
