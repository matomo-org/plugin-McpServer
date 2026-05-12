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
use Piwik\Plugins\McpServer\McpTools\ApiCallCreate;
use Piwik\Plugins\McpServer\McpTools\ApiCallDelete;
use Piwik\Plugins\McpServer\McpTools\ApiCallFull;
use Piwik\Plugins\McpServer\McpTools\ApiCallRead;
use Piwik\Plugins\McpServer\McpTools\ApiCallUpdate;
use Piwik\Plugins\McpServer\McpTools\ApiGet;
use Piwik\Plugins\McpServer\McpTools\ApiList;
use Piwik\Plugins\McpServer\McpTools\DimensionGet;
use Piwik\Plugins\McpServer\McpTools\DimensionList;
use Piwik\Plugins\McpServer\McpTools\GoalGet;
use Piwik\Plugins\McpServer\McpTools\GoalList;
use Piwik\Plugins\McpServer\McpTools\ReportList;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\McpTools\SegmentGet;
use Piwik\Plugins\McpServer\McpTools\SegmentList;
use Piwik\Plugins\McpServer\McpTools\SiteGet;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\McpTools\SiteSearch;
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

    /**
     * Tools shipped by this plugin. Held as class-strings so DI/container
     * construction stays in one place — see buildBuiltinTools().
     *
     * @var list<class-string<McpTool>>
     */
    private const BUILTIN_TOOL_CLASSES = [
        ApiCallCreate::class,
        ApiCallDelete::class,
        ApiCallFull::class,
        ApiCallRead::class,
        ApiCallUpdate::class,
        ApiGet::class,
        ApiList::class,
        DimensionGet::class,
        DimensionList::class,
        GoalGet::class,
        GoalList::class,
        ReportList::class,
        ReportMetadata::class,
        ReportProcessed::class,
        SegmentGet::class,
        SegmentList::class,
        SiteGet::class,
        SiteList::class,
        SiteSearch::class,
    ];

    /** @var array{server: Server, registry: Registry, callToolHandler: RequestHandlerInterface<mixed>}|null */
    private ?array $runtimeCache = null;

    public function __construct(
        private LoggerInterface $logger,
        private SessionStoreInterface $sessionStore,
        private ContainerInterface $container,
        private ToolCallParameterFormatter $toolCallParameterFormatter,
    ) {
    }

    /**
     * @param list<McpTool>|null $tools Built-in tools to register. When null,
     *                                  the factory resolves the shipped tool
     *                                  set via its container. Tests inject an
     *                                  explicit list to bypass DI.
     *                                  An explicit list bypasses the runtime
     *                                  cache and always builds a fresh server;
     *                                  it is never written back to the cache,
     *                                  so production calls (which pass null)
     *                                  keep seeing the shipped tool set.
     */
    public function createServer(?array $tools = null): Server
    {
        if ($tools !== null) {
            return $this->buildRuntime($tools)['server'];
        }

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
     * @param list<McpTool>|null $tools
     * @return array{server: Server, registry: Registry, callToolHandler: RequestHandlerInterface<mixed>}
     */
    private function buildRuntime(?array $tools = null): array
    {
        $tools ??= $this->buildBuiltinTools();

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
        // Every McpTool subclass exposes its handler as a public execute() method;
        // the SDK's ReferenceHandler resolves a fresh instance from the container
        // and binds JSON-RPC arguments to execute()'s typed parameters on each call.
        $builder->addTool(
            handler: [$tool::class, 'execute'],
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
     * Resolve the built-in tool class-strings into instances.
     *
     * @return list<McpTool>
     */
    private function buildBuiltinTools(): array
    {
        $tools = [];
        foreach (self::BUILTIN_TOOL_CLASSES as $toolClass) {
            $tool = $this->container->get($toolClass);
            if (!$tool instanceof McpTool) {
                throw new \LogicException(sprintf(
                    '%s did not resolve to an McpTool instance.',
                    $toolClass,
                ));
            }
            $tools[] = $tool;
        }

        return $tools;
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
