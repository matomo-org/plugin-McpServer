<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Matomo\Dependencies\McpServer\Mcp\Server;

use Matomo\Dependencies\McpServer\Mcp\Capability\Completion\ProviderInterface;
use Matomo\Dependencies\McpServer\Mcp\Capability\Discovery\CachedDiscoverer;
use Matomo\Dependencies\McpServer\Mcp\Capability\Discovery\Discoverer;
use Matomo\Dependencies\McpServer\Mcp\Capability\Discovery\DiscovererInterface;
use Matomo\Dependencies\McpServer\Mcp\Capability\Discovery\SchemaGeneratorInterface;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\Container;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ElementReference;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\Loader\ChainLoader;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\Loader\DiscoveryLoader;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\Loader\ExplicitElementLoader;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\Loader\LoaderInterface;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\Loader\ReflectedElementLoader;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ReferenceHandler;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ReferenceHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Capability\RegistryInterface;
use Matomo\Dependencies\McpServer\Mcp\Exception\InvalidArgumentException;
use Matomo\Dependencies\McpServer\Mcp\Exception\LogicException;
use Matomo\Dependencies\McpServer\Mcp\JsonRpc\MessageFactory;
use Matomo\Dependencies\McpServer\Mcp\Schema\Annotations;
use Matomo\Dependencies\McpServer\Mcp\Schema\Enum\ProtocolVersion;
use Matomo\Dependencies\McpServer\Mcp\Schema\Extension\ServerExtensionInterface;
use Matomo\Dependencies\McpServer\Mcp\Schema\Icon;
use Matomo\Dependencies\McpServer\Mcp\Schema\Implementation;
use Matomo\Dependencies\McpServer\Mcp\Schema\Prompt;
use Matomo\Dependencies\McpServer\Mcp\Schema\ResourceDefinition;
use Matomo\Dependencies\McpServer\Mcp\Schema\ResourceTemplate;
use Matomo\Dependencies\McpServer\Mcp\Schema\ServerCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Matomo\Dependencies\McpServer\Mcp\Server;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\ElementHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Notification\NotificationHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\PromptHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\ResourceHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\ResourceTemplateHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\ToolHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Resource\SessionSubscriptionManager;
use Matomo\Dependencies\McpServer\Mcp\Server\Resource\SubscriptionManagerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionManager;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionManagerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Finder\Finder;
/**
 * @phpstan-import-type Handler from ElementReference
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
final class Builder
{
    private ?Implementation $serverInfo = null;
    private RegistryInterface $registry;
    private ?SubscriptionManagerInterface $subscriptionManager = null;
    private ?LoggerInterface $logger = null;
    private ?CacheInterface $discoveryCache = null;
    private ?EventDispatcherInterface $eventDispatcher = null;
    private ?ContainerInterface $container = null;
    private ?SchemaGeneratorInterface $schemaGenerator = null;
    private ?ReferenceHandlerInterface $referenceHandler = null;
    private ?DiscovererInterface $discoverer = null;
    private ?SessionManagerInterface $sessionManager = null;
    private ?SessionStoreInterface $sessionStore = null;
    private int $gcProbability = 1;
    private int $gcDivisor = 100;
    private int $paginationLimit = 50;
    private ?string $instructions = null;
    private ?ProtocolVersion $protocolVersion = null;
    /**
     * @var array<int, RequestHandlerInterface<mixed>>
     */
    private array $requestHandlers = [];
    /**
     * @var array<int, NotificationHandlerInterface>
     */
    private array $notificationHandlers = [];
    /**
     * @var array{
     *     handler: Handler,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     annotations: ?ToolAnnotations,
     *     inputSchema: ?array<string, mixed>,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>,
     *     outputSchema: ?array<string, mixed>,
     * }[]
     */
    private array $tools = [];
    /**
     * @var array{
     *     handler: Handler,
     *     uri: string,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     mimeType: ?string,
     *     size: int|null,
     *     annotations: ?Annotations,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>
     * }[]
     */
    private array $resources = [];
    /**
     * @var array{
     *     handler: Handler,
     *     uriTemplate: string,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     mimeType: ?string,
     *     annotations: ?Annotations,
     *     meta: ?array<string, mixed>
     * }[]
     */
    private array $resourceTemplates = [];
    /**
     * @var array{
     *     handler: Handler,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>
     * }[]
     */
    private array $prompts = [];
    /**
     * @var list<array{definition: Tool, handler: ToolHandlerInterface}>
     */
    private array $explicitTools = [];
    /**
     * @var list<array{definition: ResourceDefinition, handler: ResourceHandlerInterface}>
     */
    private array $explicitResources = [];
    /**
     * @var list<array{definition: ResourceTemplate, handler: ResourceTemplateHandlerInterface, completionProviders: array<string, ProviderInterface>}>
     */
    private array $explicitResourceTemplates = [];
    /**
     * @var list<array{definition: Prompt, handler: PromptHandlerInterface, completionProviders: array<string, ProviderInterface>}>
     */
    private array $explicitPrompts = [];
    private ?string $discoveryBasePath = null;
    /**
     * @var string[]
     */
    private array $discoveryScanDirs = [];
    /**
     * @var array|string[]
     */
    private array $discoveryExcludeDirs = [];
    /**
     * @var string[]|null
     */
    private ?array $discoveryNamePatterns = null;
    private ?ServerCapabilities $serverCapabilities = null;
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $extensions = [];
    /**
     * @var LoaderInterface[]
     */
    private array $loaders = [];
    private bool $hasCustomRegistry = \false;
    private bool $lazyLoading = \true;
    /**
     * Sets the server's identity. Required.
     *
     * @param ?Icon[] $icons
     */
    public function setServerInfo(string $name, string $version, ?string $description = null, ?array $icons = null, ?string $websiteUrl = null) : self
    {
        $this->serverInfo = new Implementation(trim($name), trim($version), $description, $icons, $websiteUrl);
        return $this;
    }
    /**
     * Configures the server's pagination limit.
     */
    public function setPaginationLimit(int $paginationLimit) : self
    {
        $this->paginationLimit = $paginationLimit;
        return $this;
    }
    /**
     * Configures the instructions describing how to use the server and its features.
     *
     * This can be used by clients to improve the LLM's understanding of available tools, resources,
     * etc. It can be thought of like a "hint" to the model. For example, this information MAY
     * be added to the system prompt.
     */
    public function setInstructions(?string $instructions) : self
    {
        $this->instructions = $instructions;
        return $this;
    }
    /**
     * Explicitly set server capabilities. If set, this overrides automatic detection.
     */
    public function setCapabilities(ServerCapabilities $serverCapabilities) : self
    {
        $this->serverCapabilities = $serverCapabilities;
        return $this;
    }
    /**
     * Enable one or more MCP protocol extensions, announced to clients under
     * `capabilities.extensions` during the initialize handshake.
     *
     * @throws LogicException if the same extension is enabled more than once
     */
    public function enableExtension(ServerExtensionInterface ...$extensions) : self
    {
        foreach ($extensions as $extension) {
            $id = $extension->getId();
            if (isset($this->extensions[$id])) {
                throw new LogicException(\sprintf('Extension "%s" is already enabled.', $id));
            }
            $this->extensions[$id] = $extension->getCapabilities();
        }
        return $this;
    }
    /**
     * Register a single custom method handler.
     *
     * @param RequestHandlerInterface<mixed> $handler
     */
    public function addRequestHandler(RequestHandlerInterface $handler) : self
    {
        $this->requestHandlers[] = $handler;
        return $this;
    }
    /**
     * Register multiple custom method handlers.
     *
     * @param iterable<RequestHandlerInterface<mixed>> $handlers
     */
    public function addRequestHandlers(iterable $handlers) : self
    {
        foreach ($handlers as $handler) {
            $this->requestHandlers[] = $handler;
        }
        return $this;
    }
    /**
     * Register a single custom notification handler.
     */
    public function addNotificationHandler(NotificationHandlerInterface $handler) : self
    {
        $this->notificationHandlers[] = $handler;
        return $this;
    }
    /**
     * Register multiple custom notification handlers.
     *
     * @param iterable<int, NotificationHandlerInterface> $handlers
     */
    public function addNotificationHandlers(iterable $handlers) : self
    {
        foreach ($handlers as $handler) {
            $this->notificationHandlers[] = $handler;
        }
        return $this;
    }
    public function setRegistry(RegistryInterface $registry) : self
    {
        $this->registry = $registry;
        $this->hasCustomRegistry = \true;
        return $this;
    }
    /**
     * Controls when configured loaders (manual elements, discovery, custom loaders) run.
     *
     * Lazy (the default) defers loading to the first registry read so a persistent runtime does not
     * freeze the registry to a source not yet ready at build time. Disable to load eagerly at build.
     * A registry supplied via setRegistry() is always loaded eagerly.
     */
    public function setLazyLoading(bool $lazyLoading = \true) : self
    {
        $this->lazyLoading = $lazyLoading;
        return $this;
    }
    /**
     * Provides a PSR-3 logger instance. Defaults to NullLogger.
     */
    public function setLogger(LoggerInterface $logger) : self
    {
        $this->logger = $logger;
        return $this;
    }
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher) : self
    {
        $this->eventDispatcher = $eventDispatcher;
        return $this;
    }
    /**
     * Provides a PSR-11 DI container, primarily for resolving user-defined handler classes.
     * Defaults to a basic internal container.
     */
    public function setContainer(ContainerInterface $container) : self
    {
        $this->container = $container;
        return $this;
    }
    public function setSchemaGenerator(SchemaGeneratorInterface $schemaGenerator) : self
    {
        $this->schemaGenerator = $schemaGenerator;
        return $this;
    }
    public function setReferenceHandler(ReferenceHandlerInterface $referenceHandler) : self
    {
        $this->referenceHandler = $referenceHandler;
        return $this;
    }
    public function setDiscoverer(DiscovererInterface $discoverer) : self
    {
        $this->discoverer = $discoverer;
        return $this;
    }
    public function setResourceSubscriptionManager(SubscriptionManagerInterface $subscriptionManager) : self
    {
        $this->subscriptionManager = $subscriptionManager;
        return $this;
    }
    /**
     * Configures the session layer.
     *
     * @param int $gcProbability The numerator of the GC probability fraction (like PHP's session.gc_probability). Set to 0 to disable GC.
     * @param int $gcDivisor     The denominator of the GC probability fraction (like PHP's session.gc_divisor). Probability = gcProbability/gcDivisor.
     */
    public function setSession(?SessionStoreInterface $sessionStore = null, ?SessionManagerInterface $sessionManager = null, int $gcProbability = 1, int $gcDivisor = 100) : self
    {
        $this->sessionStore = $sessionStore;
        $this->sessionManager = $sessionManager;
        $this->gcProbability = $gcProbability;
        $this->gcDivisor = $gcDivisor;
        if (null !== $sessionManager && null !== $sessionStore) {
            throw new InvalidArgumentException('Cannot set both SessionStore and SessionManager. Set only one or the other.');
        }
        return $this;
    }
    /**
     * @param string[] $scanDirs
     * @param string[] $excludeDirs
     * @param string[] $namePatterns
     */
    public function setDiscovery(string $basePath, array $scanDirs = ['.', 'src'], array $excludeDirs = [], ?CacheInterface $cache = null, array $namePatterns = DiscovererInterface::DEFAULT_NAME_PATERNS) : self
    {
        $this->discoveryBasePath = $basePath;
        $this->discoveryScanDirs = $scanDirs;
        $this->discoveryExcludeDirs = $excludeDirs;
        $this->discoveryCache = $cache;
        $this->discoveryNamePatterns = $namePatterns;
        return $this;
    }
    public function setProtocolVersion(ProtocolVersion $protocolVersion) : self
    {
        $this->protocolVersion = $protocolVersion;
        return $this;
    }
    /**
     * Manually registers a tool handler.
     *
     * @param Handler                   $handler
     * @param ?string                   $title        Optional human-readable title for display in UI
     * @param array<string, mixed>|null $inputSchema
     * @param ?Icon[]                   $icons
     * @param array<string, mixed>|null $meta
     * @param array<string, mixed>|null $outputSchema
     */
    public function addTool(callable|array|string $handler, ?string $name = null, ?string $title = null, ?string $description = null, ?ToolAnnotations $annotations = null, ?array $inputSchema = null, ?array $icons = null, ?array $meta = null, ?array $outputSchema = null) : self
    {
        $this->tools[] = compact('handler', 'name', 'title', 'description', 'annotations', 'inputSchema', 'icons', 'meta', 'outputSchema');
        return $this;
    }
    /**
     * Manually registers a resource handler.
     *
     * @param Handler                   $handler
     * @param ?string                   $title   Optional human-readable title for display in UI
     * @param ?Icon[]                   $icons
     * @param array<string, mixed>|null $meta
     */
    public function addResource(\Closure|array|string $handler, string $uri, ?string $name = null, ?string $title = null, ?string $description = null, ?string $mimeType = null, ?int $size = null, ?Annotations $annotations = null, ?array $icons = null, ?array $meta = null) : self
    {
        $this->resources[] = compact('handler', 'uri', 'name', 'title', 'description', 'mimeType', 'size', 'annotations', 'icons', 'meta');
        return $this;
    }
    /**
     * Manually registers a resource template handler.
     *
     * @param Handler                   $handler
     * @param ?string                   $title   Optional human-readable title for display in UI
     * @param array<string, mixed>|null $meta
     */
    public function addResourceTemplate(\Closure|array|string $handler, string $uriTemplate, ?string $name = null, ?string $title = null, ?string $description = null, ?string $mimeType = null, ?Annotations $annotations = null, ?array $meta = null) : self
    {
        $this->resourceTemplates[] = compact('handler', 'uriTemplate', 'name', 'title', 'description', 'mimeType', 'annotations', 'meta');
        return $this;
    }
    /**
     * Manually registers a prompt handler.
     *
     * @param Handler                   $handler
     * @param ?Icon[]                   $icons
     * @param array<string, mixed>|null $meta
     */
    public function addPrompt(\Closure|array|string $handler, ?string $name = null, ?string $title = null, ?string $description = null, ?array $icons = null, ?array $meta = null) : self
    {
        $this->prompts[] = compact('handler', 'name', 'title', 'description', 'icons', 'meta');
        return $this;
    }
    /**
     * Registers an element using an explicit schema value object paired with a handler interface.
     *
     * Use this entry point when an element's name, schema, or description is only known at
     * runtime (e.g. config-driven integrations). For statically-known elements, prefer
     * `addTool/addResource/addResourceTemplate/addPrompt`, which can derive metadata from
     * reflection of the handler.
     *
     * Mismatched pairings (e.g. a `Tool` with a `PromptHandlerInterface`) raise
     * `Mcp\Exception\InvalidArgumentException`. Completion providers are only supported on
     * `Prompt` and `ResourceTemplate` definitions; supplying them with `Tool` or
     * `ResourceDefinition` raises the same exception.
     *
     * @param array<string, ProviderInterface> $completionProviders Keyed by argument/variable name
     */
    public function add(Tool|ResourceDefinition|ResourceTemplate|Prompt $definition, ElementHandlerInterface $handler, array $completionProviders = []) : self
    {
        if ([] !== $completionProviders && ($definition instanceof Tool || $definition instanceof ResourceDefinition)) {
            throw new InvalidArgumentException(\sprintf('Completion providers are only supported on Prompt and ResourceTemplate definitions, got %s.', $definition::class));
        }
        match (\true) {
            $definition instanceof Tool && $handler instanceof ToolHandlerInterface => $this->explicitTools[] = ['definition' => $definition, 'handler' => $handler],
            $definition instanceof ResourceDefinition && $handler instanceof ResourceHandlerInterface => $this->explicitResources[] = ['definition' => $definition, 'handler' => $handler],
            $definition instanceof ResourceTemplate && $handler instanceof ResourceTemplateHandlerInterface => $this->explicitResourceTemplates[] = ['definition' => $definition, 'handler' => $handler, 'completionProviders' => $completionProviders],
            $definition instanceof Prompt && $handler instanceof PromptHandlerInterface => $this->explicitPrompts[] = ['definition' => $definition, 'handler' => $handler, 'completionProviders' => $completionProviders],
            default => throw new InvalidArgumentException(\sprintf('%s definition cannot be paired with %s; expected the matching handler interface.', $definition::class, $handler::class)),
        };
        return $this;
    }
    /**
     * Register a single custom loader.
     */
    public function addLoader(LoaderInterface $loader) : self
    {
        $this->loaders[] = $loader;
        return $this;
    }
    /**
     * @param iterable<LoaderInterface> $loaders
     */
    public function addLoaders(iterable $loaders) : self
    {
        foreach ($loaders as $loader) {
            $this->loaders[] = $loader;
        }
        return $this;
    }
    /**
     * Builds the fully configured Server instance.
     */
    public function build() : Server
    {
        $logger = $this->logger ?? new NullLogger();
        $container = $this->container ?? new Container();
        $subscriptionManager = $this->subscriptionManager ?? new SessionSubscriptionManager($logger);
        $sessionManager = $this->sessionManager ?? new SessionManager($this->sessionStore ?? new InMemorySessionStore(), $logger, $this->gcProbability, $this->gcDivisor);
        // ExplicitElementLoader and ReflectedElementLoader run before DiscoveryLoader so manual entries are seen first;
        // DiscoveryLoader's identity check then preserves them against same-name discovered entries.
        $loaders = [...$this->loaders, new ExplicitElementLoader($this->explicitTools, $this->explicitResources, $this->explicitResourceTemplates, $this->explicitPrompts), new ReflectedElementLoader($this->tools, $this->resources, $this->resourceTemplates, $this->prompts, $logger, $this->schemaGenerator)];
        if (null !== $this->discoveryBasePath) {
            if (null !== $this->discoverer || class_exists(Finder::class)) {
                $discoverer = $this->discoverer ?? $this->createDiscoverer($logger);
                $loaders[] = new DiscoveryLoader($this->discoveryBasePath, $this->discoveryScanDirs, $this->discoveryExcludeDirs, $discoverer, $this->discoveryNamePatterns, $logger);
            } else {
                $logger->warning('File-based discovery requires symfony/finder. Skipping automatic discovery. Run: composer require symfony/finder');
            }
        }
        $chainLoader = new ChainLoader($loaders);
        if ($this->hasCustomRegistry) {
            // Builder can't inject the loader into an already-constructed instance, so load it eagerly.
            $registry = $this->registry;
            $chainLoader->load($registry);
            $eagerlyLoaded = \true;
        } else {
            $registry = new Registry($this->eventDispatcher, $logger, loader: $chainLoader);
            if (!$this->lazyLoading) {
                $registry->load();
            }
            $eagerlyLoaded = !$this->lazyLoading;
        }
        $messageFactory = MessageFactory::make();
        $capabilities = $this->serverCapabilities ?? $this->detectCapabilities($registry, $eagerlyLoaded);
        // Extensions enabled via enableExtension() are folded into caller-supplied
        // capabilities too, so setCapabilities() does not silently drop them.
        if (null !== $this->serverCapabilities && [] !== $this->extensions) {
            $capabilities = $capabilities->withExtensions($this->extensions);
        }
        $serverInfo = $this->serverInfo ?? new Implementation();
        $configuration = new Configuration($serverInfo, $capabilities, $this->paginationLimit, $this->instructions, $this->protocolVersion);
        $referenceHandler = $this->referenceHandler ?? new ReferenceHandler($container);
        $requestHandlers = array_merge($this->requestHandlers, [new Handler\Request\CallToolHandler($registry, $referenceHandler, $logger), new Handler\Request\CompletionCompleteHandler($registry, $container), new Handler\Request\GetPromptHandler($registry, $referenceHandler, $logger), new Handler\Request\InitializeHandler($configuration), new Handler\Request\ListPromptsHandler($registry, $this->paginationLimit), new Handler\Request\ListResourcesHandler($registry, $this->paginationLimit), new Handler\Request\ListResourceTemplatesHandler($registry, $this->paginationLimit), new Handler\Request\ListToolsHandler($registry, $this->paginationLimit), new Handler\Request\PingHandler(), new Handler\Request\ReadResourceHandler($registry, $referenceHandler, $logger), new Handler\Request\ResourceSubscribeHandler($registry, $subscriptionManager, $logger), new Handler\Request\ResourceUnsubscribeHandler($registry, $subscriptionManager, $logger), new Handler\Request\SetLogLevelHandler()]);
        $notificationHandlers = array_merge($this->notificationHandlers, [new Handler\Notification\InitializedHandler()]);
        $protocol = new Protocol(requestHandlers: $requestHandlers, notificationHandlers: $notificationHandlers, messageFactory: $messageFactory, sessionManager: $sessionManager, logger: $logger, eventDispatcher: $this->eventDispatcher);
        return new Server($protocol, $logger);
    }
    /**
     * When loaded, capabilities are read from the registry. When deferred, reading it would force
     * the load, so they are advertised from the configured sources instead — opaque sources (custom
     * loaders, discovery) advertise all kinds, and over-advertising is harmless per MCP semantics.
     */
    private function detectCapabilities(RegistryInterface $registry, bool $eagerlyLoaded) : ServerCapabilities
    {
        $listChanged = $this->eventDispatcher instanceof EventDispatcherInterface;
        if ($eagerlyLoaded) {
            $hasResources = $registry->hasResources() || $registry->hasResourceTemplates();
            return new ServerCapabilities(tools: $registry->hasTools(), toolsListChanged: $listChanged, resources: $hasResources, resourcesSubscribe: $hasResources, resourcesListChanged: $listChanged, prompts: $registry->hasPrompts(), promptsListChanged: $listChanged, logging: \true, completions: \true, extensions: $this->extensions ?: null);
        }
        $hasOpaqueSources = [] !== $this->loaders || null !== $this->discoveryBasePath;
        $hasResources = [] !== $this->resources || [] !== $this->explicitResources || [] !== $this->resourceTemplates || [] !== $this->explicitResourceTemplates || $hasOpaqueSources;
        return new ServerCapabilities(tools: [] !== $this->tools || [] !== $this->explicitTools || $hasOpaqueSources, toolsListChanged: $listChanged, resources: $hasResources, resourcesSubscribe: $hasResources, resourcesListChanged: $listChanged, prompts: [] !== $this->prompts || [] !== $this->explicitPrompts || $hasOpaqueSources, promptsListChanged: $listChanged, logging: \true, completions: \true, extensions: $this->extensions ?: null);
    }
    private function createDiscoverer(LoggerInterface $logger) : DiscovererInterface
    {
        $discoverer = new Discoverer($logger, null, $this->schemaGenerator);
        if (null !== $this->discoveryCache) {
            return new CachedDiscoverer($discoverer, $this->discoveryCache, $logger);
        }
        return $discoverer;
    }
}
