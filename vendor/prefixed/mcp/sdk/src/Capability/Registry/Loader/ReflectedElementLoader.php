<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Matomo\Dependencies\McpServer\Mcp\Capability\Registry\Loader;

use Matomo\Dependencies\McpServer\Mcp\Capability\Attribute\CompletionProvider;
use Matomo\Dependencies\McpServer\Mcp\Capability\Completion\EnumCompletionProvider;
use Matomo\Dependencies\McpServer\Mcp\Capability\Completion\ListCompletionProvider;
use Matomo\Dependencies\McpServer\Mcp\Capability\Completion\ProviderInterface;
use Matomo\Dependencies\McpServer\Mcp\Capability\Discovery\DocBlockParser;
use Matomo\Dependencies\McpServer\Mcp\Capability\Discovery\HandlerResolver;
use Matomo\Dependencies\McpServer\Mcp\Capability\Discovery\SchemaGenerator;
use Matomo\Dependencies\McpServer\Mcp\Capability\Discovery\SchemaGeneratorInterface;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ElementReference;
use Matomo\Dependencies\McpServer\Mcp\Capability\RegistryInterface;
use Matomo\Dependencies\McpServer\Mcp\Exception\ConfigurationException;
use Matomo\Dependencies\McpServer\Mcp\Schema\Annotations;
use Matomo\Dependencies\McpServer\Mcp\Schema\Icon;
use Matomo\Dependencies\McpServer\Mcp\Schema\Prompt;
use Matomo\Dependencies\McpServer\Mcp\Schema\PromptArgument;
use Matomo\Dependencies\McpServer\Mcp\Schema\ResourceDefinition;
use Matomo\Dependencies\McpServer\Mcp\Schema\ResourceTemplate;
use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
/**
 * @author Antoine Bluchet <soyuka@gmail.com>
 *
 * @phpstan-import-type Handler from ElementReference
 */
final class ReflectedElementLoader implements LoaderInterface
{
    /**
     * @param array{
     *     handler: Handler,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     annotations: ?ToolAnnotations,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>,
     *     outputSchema: ?array<string, mixed>
     * }[] $tools
     * @param array{
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
     * }[] $resources
     * @param array{
     *     handler: Handler,
     *     uriTemplate: string,
     *     name: ?string,
     *     title: ?string,
     *     description: ?string,
     *     mimeType: ?string,
     *     annotations: ?Annotations,
     *     meta: ?array<string, mixed>
     * }[] $resourceTemplates
     * @param array{
     *     handler: Handler,
     *     name: ?string,
     *     description: ?string,
     *     icons: ?Icon[],
     *     meta: ?array<string, mixed>
     * }[] $prompts
     */
    public function __construct(private readonly array $tools = [], private readonly array $resources = [], private readonly array $resourceTemplates = [], private readonly array $prompts = [], private LoggerInterface $logger = new NullLogger(), private ?SchemaGeneratorInterface $schemaGenerator = null)
    {
    }
    public function load(RegistryInterface $registry) : void
    {
        $docBlockParser = new DocBlockParser(logger: $this->logger);
        $schemaGenerator = $this->schemaGenerator ?? new SchemaGenerator($docBlockParser);
        // Register Tools
        foreach ($this->tools as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);
                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_tool_' . spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);
                    $name = $data['name'] ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getDescription($docBlock) ?? null;
                }
                $inputSchema = $data['inputSchema'] ?? $schemaGenerator->generate($reflection);
                $tool = new Tool(name: $name, title: $data['title'] ?? null, inputSchema: $inputSchema, description: $description, annotations: $data['annotations'] ?? null, icons: $data['icons'] ?? null, meta: $data['meta'] ?? null, outputSchema: $data['outputSchema'] ?? null);
                $registry->registerTool($tool, $data['handler']);
                $handlerDesc = $this->getHandlerDescription($data['handler']);
                $this->logger->debug("Registered manual tool {$name} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $this->logger->error('Failed to register manual tool', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual tool '{$data['name']}': {$e->getMessage()}", 0, $e);
            }
        }
        // Register Resources
        foreach ($this->resources as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);
                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_resource_' . spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);
                    $name = $data['name'] ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getDescription($docBlock) ?? null;
                }
                $resource = new ResourceDefinition(uri: $data['uri'], name: $name, title: $data['title'] ?? null, description: $description, mimeType: $data['mimeType'] ?? null, annotations: $data['annotations'] ?? null, size: $data['size'] ?? null, icons: $data['icons'] ?? null, meta: $data['meta'] ?? null);
                $registry->registerResource($resource, $data['handler']);
                $handlerDesc = $this->getHandlerDescription($data['handler']);
                $this->logger->debug("Registered manual resource {$name} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $this->logger->error('Failed to register manual resource', ['handler' => $data['handler'], 'uri' => $data['uri'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual resource '{$data['uri']}': {$e->getMessage()}", 0, $e);
            }
        }
        // Register Templates
        foreach ($this->resourceTemplates as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);
                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_template_' . spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);
                    $name = $data['name'] ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getDescription($docBlock) ?? null;
                }
                $template = new ResourceTemplate(uriTemplate: $data['uriTemplate'], name: $name, title: $data['title'] ?? null, description: $description, mimeType: $data['mimeType'] ?? null, annotations: $data['annotations'] ?? null, meta: $data['meta'] ?? null);
                $completionProviders = $this->getCompletionProviders($reflection);
                $registry->registerResourceTemplate($template, $data['handler'], $completionProviders);
                $handlerDesc = $this->getHandlerDescription($data['handler']);
                $this->logger->debug("Registered manual template {$name} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $this->logger->error('Failed to register manual template', ['handler' => $data['handler'], 'uriTemplate' => $data['uriTemplate'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual resource template '{$data['uriTemplate']}': {$e->getMessage()}", 0, $e);
            }
        }
        // Register Prompts
        foreach ($this->prompts as $data) {
            try {
                $reflection = HandlerResolver::resolve($data['handler']);
                if ($reflection instanceof \ReflectionFunction) {
                    $name = $data['name'] ?? 'closure_prompt_' . spl_object_id($data['handler']);
                    $description = $data['description'] ?? null;
                } else {
                    $classShortName = $reflection->getDeclaringClass()->getShortName();
                    $methodName = $reflection->getName();
                    $docBlock = $docBlockParser->parseDocBlock($reflection->getDocComment() ?? null);
                    $name = $data['name'] ?? ('__invoke' === $methodName ? $classShortName : $methodName);
                    $description = $data['description'] ?? $docBlockParser->getDescription($docBlock) ?? null;
                }
                $arguments = [];
                $paramTags = $reflection instanceof \ReflectionMethod ? $docBlockParser->getParamTags($docBlockParser->parseDocBlock($reflection->getDocComment() ?? null)) : [];
                foreach ($reflection->getParameters() as $param) {
                    $reflectionType = $param->getType();
                    // Basic DI check (heuristic)
                    if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                        continue;
                    }
                    $paramTag = $paramTags['$' . $param->getName()] ?? null;
                    $arguments[] = new PromptArgument($param->getName(), $paramTag ? trim((string) $paramTag->getDescription()) : null, !$param->isOptional() && !$param->isDefaultValueAvailable());
                }
                $prompt = new Prompt(name: $name, title: $data['title'] ?? null, description: $description, arguments: $arguments, icons: $data['icons'] ?? null, meta: $data['meta'] ?? null);
                $completionProviders = $this->getCompletionProviders($reflection);
                $registry->registerPrompt($prompt, $data['handler'], $completionProviders);
                $handlerDesc = $this->getHandlerDescription($data['handler']);
                $this->logger->debug("Registered manual prompt {$name} from handler {$handlerDesc}");
            } catch (\Throwable $e) {
                $this->logger->error('Failed to register manual prompt', ['handler' => $data['handler'], 'name' => $data['name'], 'exception' => $e]);
                throw new ConfigurationException("Error registering manual prompt '{$data['name']}': {$e->getMessage()}", 0, $e);
            }
        }
        $this->logger->debug('Manual element registration complete.');
    }
    /**
     * @param Handler $handler
     */
    private function getHandlerDescription(\Closure|array|string $handler) : string
    {
        if ($handler instanceof \Closure) {
            return 'Closure';
        }
        if (\is_array($handler)) {
            return \sprintf('%s::%s', \is_object($handler[0]) ? $handler[0]::class : $handler[0], $handler[1]);
        }
        return (string) $handler;
    }
    /**
     * @return array<string, ProviderInterface>
     */
    private function getCompletionProviders(\ReflectionMethod|\ReflectionFunction $reflection) : array
    {
        $completionProviders = [];
        foreach ($reflection->getParameters() as $param) {
            $reflectionType = $param->getType();
            if ($reflectionType instanceof \ReflectionNamedType && !$reflectionType->isBuiltin()) {
                continue;
            }
            $completionAttributes = $param->getAttributes(CompletionProvider::class, \ReflectionAttribute::IS_INSTANCEOF);
            if (!empty($completionAttributes)) {
                $attributeInstance = $completionAttributes[0]->newInstance();
                if ($attributeInstance->provider) {
                    $completionProviders[$param->getName()] = $attributeInstance->provider;
                } elseif ($attributeInstance->providerClass) {
                    $completionProviders[$param->getName()] = $attributeInstance->providerClass;
                } elseif ($attributeInstance->values) {
                    $completionProviders[$param->getName()] = new ListCompletionProvider($attributeInstance->values);
                } elseif ($attributeInstance->enum) {
                    $completionProviders[$param->getName()] = new EnumCompletionProvider($attributeInstance->enum);
                }
            }
        }
        return $completionProviders;
    }
}
