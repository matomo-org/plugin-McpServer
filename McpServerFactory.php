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
use Matomo\Dependencies\McpServer\Mcp\Schema\Icon;
use Matomo\Dependencies\McpServer\Mcp\Schema\ServerCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Matomo\Dependencies\McpServer\Mcp\Server;
use Matomo\Dependencies\McpServer\Mcp\Server\Builder;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\McpToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\McpToolIcon;
use Piwik\Plugins\McpServer\Server\Handler\Request\CompatibleCallToolHandler;
use Piwik\Plugins\McpServer\Server\Handler\Request\ObservedCallToolHandler;
use Piwik\Plugins\McpServer\Server\InternalAccess;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

final class McpServerFactory
{
    private const DEFAULT_TOOL_CALL_LOG_LEVEL = 'DEBUG';
    /** @var array<int, string> */
    private const VALID_TOOL_CALL_LOG_LEVELS = ['ERROR', 'WARN', 'WARNING', 'INFO', 'DEBUG', 'VERBOSE'];

    /** @var array{server: Server, registry: Registry, callToolHandler: RequestHandlerInterface<mixed>}|null */
    private ?array $runtimeCache = null;

    public function __construct(
        private LoggerInterface $logger,
        private SessionStoreInterface $sessionStore,
        private ContainerInterface $container,
        private ToolCallParameterFormatter $toolCallParameterFormatter,
        private McpToolsProviderInterface $toolsProvider,
    ) {
    }

    public function createServer(): Server
    {
        return $this->resolveRuntime()['server'];
    }

    public function createInternalAccess(): InternalAccess
    {
        $runtime = $this->resolveRuntime();

        return new InternalAccess(
            $runtime['registry'],
            $runtime['callToolHandler'],
        );
    }

    /**
     * Discard the memoised runtime so the next {@see createServer} or
     * {@see createInternalAccess} call rebuilds against the current settings.
     *
     * The factory is a container singleton, so the cached runtime lives for the
     * whole PHP process — one request under PHP-FPM, but potentially many logical
     * operations in a long-running CLI worker. Production code still does not need
     * to clear it: MCP settings and tool registration are stable for the process
     * lifetime, and per-call state is isolated separately by the fresh in-memory
     * session {@see InternalToolCaller} builds on every call. Tests that toggle
     * settings affecting tool registration between builds must call this to drop
     * the now-stale runtime.
     *
     * @internal
     */
    public function clearRuntimeCache(): void
    {
        $this->runtimeCache = null;
    }

    /**
     * Memoise the MCP runtime so callers that hit the factory multiple times in
     * one request (e.g. {@see getInternalToolCatalog} followed by repeated
     * {@see callInternalTool} dispatches) reuse the same registry and handler
     * chain.
     *
     * @return array{server: Server, registry: Registry, callToolHandler: RequestHandlerInterface<mixed>}
     */
    private function resolveRuntime(): array
    {
        if ($this->runtimeCache !== null) {
            return $this->runtimeCache;
        }

        return $this->runtimeCache = $this->buildRuntime();
    }

    /**
     * @return array{server: Server, registry: Registry, callToolHandler: RequestHandlerInterface<mixed>}
     */
    private function buildRuntime(): array
    {
        $tools = $this->toolsProvider->getAllTools();

        $version = (string) Manager::getInstance()->getVersion('McpServer');
        $loggingConfig = $this->resolveLoggingConfig();

        $registry = new Registry(logger: new NullLogger());

        $builder = Server::builder()
            ->setServerInfo('Matomo MCP Server', $version)
            ->setLogger(new NullLogger())
            ->setRegistry($registry)
            ->setSession($this->sessionStore)
            ->setContainer($this->container)
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

        foreach ($tools as $tool) {
            if (!$tool->shouldRegister()) {
                continue;
            }

            $this->registerTool($builder, $tool);
        }

        $callToolHandler = new CompatibleCallToolHandler(
            $registry,
            new ReferenceHandler($this->container),
            new NullLogger(),
        );

        $activeCallToolHandler = $callToolHandler;
        if ($loggingConfig['logToolCalls']) {
            $activeCallToolHandler = new ObservedCallToolHandler(
                $callToolHandler,
                $this->logger,
                $this->toolCallParameterFormatter,
                $loggingConfig['logFullParameters'],
                $loggingConfig['logLevel'],
            );
        }
        $builder->addRequestHandler($activeCallToolHandler);

        $server = $builder->build();

        return [
            'server' => $server,
            'registry' => $registry,
            'callToolHandler' => $activeCallToolHandler,
        ];
    }

    private function registerTool(Builder $builder, McpTool $tool): void
    {
        // Every McpTool subclass exposes its handler as a public callable execute()
        // method. Register a closure bound to the resolved object so tools contributed
        // through McpServer.addTools keep their constructor state while the SDK
        // can still reflect execute()'s typed parameter contract.
        $handler = [$tool, 'execute'];
        assert(is_callable($handler));

        $builder->addTool(
            handler: \Closure::fromCallable($handler),
            name: $tool->getName(),
            title: $tool->getTitle(),
            description: $tool->getDescription(),
            annotations: $this->adaptAnnotations($tool->getAnnotations()),
            inputSchema: $tool->getInputSchema(),
            icons: $this->adaptIcons($tool->getIcons()),
            meta: $tool->getMeta(),
            outputSchema: $tool->getOutputSchema(),
        );
    }

    /**
     * Translate Matomo-owned annotation hints into the form expected at
     * tool registration.
     */
    private function adaptAnnotations(McpToolAnnotations $annotations): ToolAnnotations
    {
        return new ToolAnnotations(
            readOnlyHint: $annotations->readOnlyHint,
            destructiveHint: $annotations->destructiveHint,
            idempotentHint: $annotations->idempotentHint,
            openWorldHint: $annotations->openWorldHint,
        );
    }

    /**
     * @param list<McpToolIcon>|null $icons
     * @return list<Icon>|null
     */
    private function adaptIcons(?array $icons): ?array
    {
        if ($icons === null) {
            return null;
        }

        return array_map(
            static fn(McpToolIcon $icon): Icon => new Icon(
                src: $icon->src,
                mimeType: $icon->mimeType,
                sizes: $icon->sizes,
            ),
            $icons,
        );
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

    /**
     * @return array<string, mixed>
     */
    private function getMcpServerConfig(): array
    {
        $config = Config::getInstance()->McpServer ?? [];

        return is_array($config) ? $config : [];
    }
}
