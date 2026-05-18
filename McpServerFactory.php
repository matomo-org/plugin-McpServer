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
use Matomo\Dependencies\McpServer\Mcp\Server;
use Matomo\Dependencies\McpServer\Mcp\Server\Builder;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\Contracts\McpTool;
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
     */
    public function createServer(?array $tools = null): Server
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
            annotations: $tool->getAnnotations(),
            inputSchema: $tool->getInputSchema(),
            icons: $tool->getIcons(),
            meta: $tool->getMeta(),
            outputSchema: $tool->getOutputSchema(),
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
