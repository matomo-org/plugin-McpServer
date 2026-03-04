<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Matomo\Dependencies\McpServer\Mcp\Capability\Registry;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ReferenceHandler;
use Matomo\Dependencies\McpServer\Mcp\Schema\ServerCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Matomo\Dependencies\McpServer\Mcp\Server;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\McpTools\ApiList;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\Schemas\Api\ApiListToolInputSchema;
use Piwik\Plugins\McpServer\Schemas\Api\ApiMethodSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportProcessedToolOutputSchema;
use Piwik\Plugins\McpServer\Server\Handler\Request\CompatibleCallToolHandler;
use Piwik\Plugins\McpServer\Server\Handler\Request\ObservedCallToolHandler;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

final class McpServerFactory
{
    private const DEFAULT_TOOL_CALL_LOG_LEVEL = 'DEBUG';
    /** @var array<int, string> */
    private const VALID_TOOL_CALL_LOG_LEVELS = ['ERROR', 'WARN', 'WARNING', 'INFO', 'DEBUG', 'VERBOSE'];

    public function __construct(
        private LoggerInterface $logger,
        private SessionStoreInterface $sessionStore,
        private ContainerInterface $container,
        private ToolCallParameterFormatter $toolCallParameterFormatter,
    ) {
    }

    public function createServer(): Server
    {
        $version = (string) Manager::getInstance()->getVersion('McpServer');
        $loggingConfig = $this->resolveLoggingConfig();

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

        $builder->addTool(
            handler: [ReportProcessed::class, 'get'],
            name: ReportProcessed::TOOL_NAME,
            description: ReportProcessed::TOOL_DESCRIPTION,
            annotations: new ToolAnnotations(
                // Classify range aggregate materialization as non-mutational for MCP.
                readOnlyHint: ReportProcessed::isReadOnlyInCurrentMatomoConfig(),
                destructiveHint: false,
                idempotentHint: false,
                openWorldHint: false,
            ),
            inputSchema: ReportProcessed::INPUT_SCHEMA,
            outputSchema: ReportProcessedToolOutputSchema::ITEM,
        );

        $rawApiAccessMode = $this->resolveRawApiAccessMode();
        if (RawApiAccessMode::allowsToolRegistration($rawApiAccessMode)) {
            // This tool is registered manually (not via attribute discovery)
            // so registration can be gated by the raw API access mode.
            $builder->addTool(
                [ApiList::class, 'list'],
                ApiList::TOOL_NAME,
                "Use when: you need discoverable Matomo API methods and parameter metadata.\n"
                    . "Purpose: return paginated API method summaries aligned with Matomo API docs visibility.\n"
                    . "Next: choose a method and map parameters for subsequent raw API tooling.",
                new ToolAnnotations(
                    readOnlyHint: true,
                    destructiveHint: false,
                    idempotentHint: true,
                    openWorldHint: false,
                ),
                ApiListToolInputSchema::SCHEMA,
                null,
                null,
                ApiMethodSummaryToolOutputSchema::PAGINATED_LIST,
            );
        }

        $callToolHandler = new CompatibleCallToolHandler(
            $registry,
            new ReferenceHandler($this->container),
            new NullLogger(),
        );

        if ($loggingConfig['logToolCalls']) {
            $builder->addRequestHandler(new ObservedCallToolHandler(
                $callToolHandler,
                $this->logger,
                $this->toolCallParameterFormatter,
                $loggingConfig['logFullParameters'],
                $loggingConfig['logLevel'],
            ));
        } else {
            $builder->addRequestHandler($callToolHandler);
        }

        return $builder->build();
    }

    /**
     * @return array{logToolCalls: bool, logFullParameters: bool, logLevel: string}
     */
    private function resolveLoggingConfig(): array
    {
        $config = $this->getMcpServerConfig();

        return [
            'logToolCalls' => $this->readEnabledFlag($config, 'log_tool_calls'),
            'logFullParameters' => $this->readEnabledFlag($config, 'log_tool_call_parameters_full'),
            'logLevel' => $this->resolveToolCallLogLevel($config),
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private function readEnabledFlag(array $config, string $key): bool
    {
        if (!array_key_exists($key, $config)) {
            return false;
        }

        $value = $config[$key];

        return $value === true || $value === 1 || $value === '1';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function resolveToolCallLogLevel(array $config): string
    {
        $configuredLevel = $config['log_tool_call_level'] ?? null;

        if (!is_scalar($configuredLevel)) {
            return self::DEFAULT_TOOL_CALL_LOG_LEVEL;
        }

        $normalizedLevel = strtoupper(trim((string) $configuredLevel));
        if (!in_array($normalizedLevel, self::VALID_TOOL_CALL_LOG_LEVELS, true)) {
            return self::DEFAULT_TOOL_CALL_LOG_LEVEL;
        }

        return $normalizedLevel;
    }

    private function resolveRawApiAccessMode(): string
    {
        $config = $this->getMcpServerConfig();
        return RawApiAccessMode::normalize($config['raw_api_access_mode'] ?? null);
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
