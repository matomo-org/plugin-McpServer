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
use Matomo\Dependencies\McpServer\Mcp\Server\Transport\Http\Middleware\ProtocolVersionMiddleware;
use Matomo\Dependencies\McpServer\Mcp\Server\Transport\StreamableHttpTransport;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\McpToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\McpToolIcon;
use Piwik\Plugins\McpServer\Server\Handler\Request\CompatibleCallToolHandler;
use Piwik\Plugins\McpServer\Server\Handler\Request\ObservedCallToolHandler;
use Piwik\Plugins\McpServer\Server\InternalAccess;
use Piwik\Plugins\McpServer\Server\ServerEventBridge;
use Piwik\Plugins\McpServer\Server\Transport\MatomoHostValidationMiddleware;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Url;
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

        // Intake normalization keys on the tool class, which is only known here. A listener
        // displacing a built-in name through McpServer.addTools registers its own class and so
        // carries no profile.
        $toolClasses = [];
        foreach ($tools as $tool) {
            if (!$tool->shouldRegister()) {
                continue;
            }

            $this->registerTool($builder, $tool);
            $toolClasses[$tool->getName()] = $tool::class;
        }

        $callToolHandler = new CompatibleCallToolHandler(
            $registry,
            new ReferenceHandler($this->container),
            toolClasses: $toolClasses,
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

        // Observability seam: publish each completed request and received notification as a
        // neutral McpServerEvent so other plugins can observe MCP usage without depending on the SDK.
        // A no-op when nothing subscribes to McpServer.serverEvent.
        $builder->setEventDispatcher(new ServerEventBridge($this->logger));

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

    /**
     * Build the streamable HTTP transport for the MCP endpoint.
     *
     * The SDK's default middleware stack only trusts localhost hosts, which
     * 403s every real deployment. We replace its raw-`Host` DNS-rebinding check
     * with {@see MatomoHostValidationMiddleware} — which validates the
     * proxy-aware host, and a supplied `Origin`, against the deployment's own
     * trusted hostnames — and keep the protocol-version middleware. Passing an
     * explicit list is what disables the SDK defaults; see
     * {@see StreamableHttpTransport::defaultMiddleware()}.
     *
     * We deliberately drop the SDK's `CorsMiddleware`: browser/CORS access to
     * MCP is unsupported (see {@see MatomoHostValidationMiddleware}), and Matomo
     * core already emits the `Access-Control-Allow-Origin` response header from
     * `[General] cors_domains` at the API layer ({@see \Piwik\API\Request} →
     * {@see \Piwik\API\CORSHandler}) for this endpoint like every other API
     * method, so a transport-level CORS layer would only duplicate or conflict
     * with it. A CORS preflight (`OPTIONS`) cannot complete anyway: it carries
     * no bearer token, so {@see \Piwik\Plugins\McpServer\Support\Access\McpAccessGate::assertHttp()}
     * rejects it as anonymous before the transport runs.
     *
     * Because we pin an explicit list, new SDK default protections are not
     * inherited automatically — re-check {@see StreamableHttpTransport::defaultMiddleware()}
     * against this list when bumping `mcp/sdk`.
     *
     * Host/Origin validation is defense-in-depth only: the endpoint is already
     * token-authenticated before the transport runs. See
     * {@see resolveAllowedOriginHosts()} for how the trusted-host allowlist is
     * derived.
     *
     * Static because the middleware stack is derived solely from global config
     * ({@see \Piwik\Config} and {@see \Piwik\Url}); it needs none of the
     * factory's injected dependencies, so callers (including tests) can build a
     * transport without resolving the factory from the container.
     */
    public static function createTransport(ServerRequestInterface $request): StreamableHttpTransport
    {
        $middleware = [
            new MatomoHostValidationMiddleware(self::resolveAllowedOriginHosts()),
            new ProtocolVersionMiddleware(),
        ];

        return new StreamableHttpTransport($request, middleware: $middleware);
    }

    /**
     * The trusted-host allowlist for {@see MatomoHostValidationMiddleware}: the
     * hostnames this deployment answers to, against which a supplied `Origin` is
     * validated for DNS-rebinding protection. Derived only from
     * `[General] trusted_hosts` — never `cors_domains`, which governs Matomo's
     * CORS response headers, not who may reach the MCP endpoint. Accepting an
     * `Origin` here is request validation, not CORS support; cross-origin
     * browser MCP stays unsupported (see {@see createTransport()}).
     *
     * Always the full `trusted_hosts` list, independent of
     * `[General] enable_trusted_host_check`: that flag relaxes Matomo's host
     * check for no-`Origin` native requests, but must not waive validation of a
     * supplied `Origin`. Entries are passed through verbatim — the middleware
     * owns normalization (port/case/trailing-dot) so the allowlist and the
     * incoming `Origin` reduce through one codepath. The list may be empty on a
     * not-yet-configured instance, and an empty list makes the middleware reject
     * every supplied `Origin` — fail-closed, because without a trusted-host
     * policy the deployment's own hostnames are unknown.
     *
     * @return list<string>
     */
    private static function resolveAllowedOriginHosts(): array
    {
        return array_values(Url::getTrustedHostsFromConfig());
    }
}
