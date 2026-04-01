<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use PHPUnit\Framework\TestCase;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
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
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
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
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
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
                    && ($context['formatted_arguments'] ?? null) === 'query: "secret"'),
            );

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', ['query' => 'secret'], 'missing-full-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testBooleanTrueConfigEnablesToolCallLogging(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => true];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-bool-1');
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
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-2');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testStringZeroConfigDisablesToolCallLogging(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => '0'];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-string-zero-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testStringTrueConfigDoesNotEnableToolCallLogging(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 'true'];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-string-true-1');
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
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-3');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testConfiguredWarnLevelUsesWarning(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => 'warn',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::never())->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-warn-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testConfiguredErrorLevelUsesError(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => 'ERROR',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::never())->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-error-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testConfiguredInfoLevelUsesInfo(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => ' INFO ',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');
        $logger->expects(self::never())->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-info-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testConfiguredVerboseLevelUsesDebug(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => 'VERBOSE',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-verbose-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testInvalidToolCallLogLevelFallsBackToDebug(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => 'TRACE',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug');
        $logger->expects(self::never())->method('info');
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('error');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub(),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-invalid-level-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testRawApiListToolIsVisibleWhenRawAccessModeAllowsDirectApiAccess(): void
    {
        $toolsWhenRead = $this->listToolNamesForCurrentConfig('read');
        self::assertContains('matomo_api_call', $toolsWhenRead);
        self::assertContains('matomo_api_get', $toolsWhenRead);
        self::assertContains('matomo_api_list', $toolsWhenRead);

        $toolsWhenCreate = $this->listToolNamesForCurrentConfig('create');
        self::assertContains('matomo_api_call', $toolsWhenCreate);
        self::assertContains('matomo_api_get', $toolsWhenCreate);
        self::assertContains('matomo_api_list', $toolsWhenCreate);

        $toolsWhenUpdate = $this->listToolNamesForCurrentConfig('update');
        self::assertContains('matomo_api_call', $toolsWhenUpdate);
        self::assertContains('matomo_api_get', $toolsWhenUpdate);
        self::assertContains('matomo_api_list', $toolsWhenUpdate);

        $toolsWhenDelete = $this->listToolNamesForCurrentConfig('delete');
        self::assertContains('matomo_api_call', $toolsWhenDelete);
        self::assertContains('matomo_api_get', $toolsWhenDelete);
        self::assertContains('matomo_api_list', $toolsWhenDelete);

        $toolsWhenFull = $this->listToolNamesForCurrentConfig('full');
        self::assertContains('matomo_api_call', $toolsWhenFull);
        self::assertContains('matomo_api_get', $toolsWhenFull);
        self::assertContains('matomo_api_list', $toolsWhenFull);
    }

    public function testRawApiGetToolHasFullAnnotationsWhenVisible(): void
    {
        $toolsWhenRead = $this->listToolsByNameForCurrentConfig('read');

        self::assertArrayHasKey('matomo_api_get', $toolsWhenRead);
        $toolWhenRead = $toolsWhenRead['matomo_api_get'];
        self::assertNotNull($toolWhenRead->annotations);
        self::assertTrue($toolWhenRead->annotations->readOnlyHint);
        self::assertFalse($toolWhenRead->annotations->destructiveHint);
        self::assertTrue($toolWhenRead->annotations->idempotentHint);
        self::assertFalse($toolWhenRead->annotations->openWorldHint);

        $toolsWhenFull = $this->listToolsByNameForCurrentConfig('full');

        self::assertArrayHasKey('matomo_api_get', $toolsWhenFull);
        $toolWhenFull = $toolsWhenFull['matomo_api_get'];
        self::assertNotNull($toolWhenFull->annotations);
        self::assertTrue($toolWhenFull->annotations->readOnlyHint);
        self::assertFalse($toolWhenFull->annotations->destructiveHint);
        self::assertTrue($toolWhenFull->annotations->idempotentHint);
        self::assertFalse($toolWhenFull->annotations->openWorldHint);
    }

    public function testRawApiCallToolHasFullAnnotationsWhenVisible(): void
    {
        $toolsWhenRead = $this->listToolsByNameForCurrentConfig('read');

        self::assertArrayHasKey('matomo_api_call', $toolsWhenRead);
        $toolWhenRead = $toolsWhenRead['matomo_api_call'];
        self::assertNotNull($toolWhenRead->annotations);
        self::assertFalse($toolWhenRead->annotations->readOnlyHint);
        self::assertFalse($toolWhenRead->annotations->destructiveHint);
        self::assertFalse($toolWhenRead->annotations->idempotentHint);
        self::assertFalse($toolWhenRead->annotations->openWorldHint);

        $toolsWhenFull = $this->listToolsByNameForCurrentConfig('full');

        self::assertArrayHasKey('matomo_api_call', $toolsWhenFull);
        $toolWhenFull = $toolsWhenFull['matomo_api_call'];
        self::assertNotNull($toolWhenFull->annotations);
        self::assertFalse($toolWhenFull->annotations->readOnlyHint);
        self::assertTrue($toolWhenFull->annotations->destructiveHint);
        self::assertFalse($toolWhenFull->annotations->idempotentHint);
        self::assertFalse($toolWhenFull->annotations->openWorldHint);
    }

    /**
     * @return list<string>
     */
    private function listToolNamesForCurrentConfig(string $rawApiAccessMode = 'none'): array
    {
        return array_keys($this->listToolsByNameForCurrentConfig($rawApiAccessMode));
    }

    /**
     * @return array<string, Tool>
     */
    private function listToolsByNameForCurrentConfig(string $rawApiAccessMode = 'none'): array
    {
        $factory = new McpServerFactory(
            $this->createMock(LoggerInterface::class),
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            $this->createSystemSettingsStub($rawApiAccessMode),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-tools-1');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        $toolsByName = [];
        foreach ($result->tools as $tool) {
            $toolsByName[$tool->name] = $tool;
        }

        return $toolsByName;
    }

    private function createSystemSettingsStub(string $rawApiAccessMode = 'none'): SystemSettings
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('getRawApiAccessMode')
            ->willReturn($rawApiAccessMode);

        return $settings;
    }
}
