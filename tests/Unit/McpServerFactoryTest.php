<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Piwik\Config;
use Piwik\Plugin\Manager;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class McpServerFactoryTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalMcpServerConfig = null;

    public function setUp(): void
    {
        parent::setUp();

        $originalConfig = Config::getInstance()->McpServer ?? null;
        $this->originalMcpServerConfig = is_array($originalConfig) ? $originalConfig : null;
    }

    public function tearDown(): void
    {
        Config::getInstance()->McpServer = $this->originalMcpServerConfig;

        parent::tearDown();
    }

    public function testInitializeResponseHasExpectedServerInfoAndCapabilities(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 1];

        $factory = new McpServerFactory(
            $this->createMock(LoggerInterface::class),
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter()
        );
        $server = $factory->createServer();
        $payload = McpTestHelper::makeInitializeRequest('init-1');

        $response = McpTestHelper::postJson($server, $payload);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseInitialize($message);
        $pluginVersion = (string) Manager::getInstance()->getVersion('McpServer');

        self::assertSame('Matomo MCP Server', $result->serverInfo->name);
        self::assertSame($pluginVersion, $result->serverInfo->version);

        $capabilities = $result->capabilities;

        self::assertTrue($capabilities->tools);
        self::assertNull($capabilities->toolsListChanged);
        self::assertFalse($capabilities->resources);
        self::assertNull($capabilities->resourcesSubscribe);
        self::assertNull($capabilities->resourcesListChanged);
        self::assertFalse($capabilities->prompts);
        self::assertNull($capabilities->promptsListChanged);
        self::assertFalse($capabilities->logging);
        self::assertFalse($capabilities->completions);
    }

    public function testToolCallLoggingEnabledInjectsObservedHandler(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 1];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter()
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testStringOneConfigEnablesFullParameterLogging(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => '1',
            'log_tool_call_parameters_full' => '1',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                self::callback(static function (string $message): bool {
                    return $message === 'MCP Tool Call failed: {error_message} '
                        . '[{formatted_arguments}] [session={session_id}]';
                }),
                self::callback(static fn(mixed $context): bool => is_array($context)
                    && ($context['error_message'] ?? null) === 'Tool not found: "missing_tool".'
                    && ($context['formatted_arguments'] ?? null) === 'query: "secret"')
            );

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter()
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', ['query' => 'secret'], 'missing-full-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testToolCallLoggingDisabledSkipsObservedHandlerInjection(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 0];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter()
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-2');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testToolCallLoggingMissingConfigSkipsObservedHandlerInjection(): void
    {
        Config::getInstance()->McpServer = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter()
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-3');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }
}
