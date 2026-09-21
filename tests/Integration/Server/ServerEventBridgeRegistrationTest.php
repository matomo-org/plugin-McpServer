<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\Server;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\MessageInterface;
use Matomo\Dependencies\McpServer\Mcp\Schema\Notification\InitializedNotification;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\PingRequest;
use Matomo\Dependencies\McpServer\Mcp\Server;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Contracts\Events\McpInitializedEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpInitializeEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpServerEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolCallEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolsListEvent;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Plugins\McpServer\tests\Framework\FixedMcpToolsProvider;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\StubMcpTool;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Psr\Container\ContainerInterface;

/**
 * Verifies that {@see McpServerFactory} wires up MCP observability end to end, so that driving
 * real JSON-RPC requests through the server re-publishes them on the `McpServer.serverEvent`
 * Matomo event: the {@see ServerEventBridge} as the bundled server's PSR-14 event dispatcher for
 * the handshake lifecycle, and the publishing handler decorators for tool activity in either
 * protocol era.
 *
 * @group McpServer
 * @group Plugins
 */
class ServerEventBridgeRegistrationTest extends IntegrationTestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalMcpServerConfig = null;

    /** @var list<McpServerEvent> */
    private array $captured = [];

    public function setUp(): void
    {
        parent::setUp();

        $originalConfig = Config::getInstance()->McpServer ?? null;
        $this->originalMcpServerConfig = is_array($originalConfig) ? $originalConfig : null;
        Config::getInstance()->McpServer = [];

        $this->captured = [];
        Piwik::addAction('McpServer.serverEvent', function (McpServerEvent $event): void {
            $this->captured[] = $event;
        });
    }

    public function tearDown(): void
    {
        Config::getInstance()->McpServer = $this->originalMcpServerConfig;

        parent::tearDown();
    }

    public function testInitializeRequestPublishesInitializeEvent(): void
    {
        $server = $this->buildServer();

        McpTestHelper::initializeSession($server);

        $event = $this->onlyCaptured(McpInitializeEvent::class);
        self::assertSame('test-client', $event->clientName);
        self::assertSame('1.0.0', $event->clientVersion);
        self::assertSame('http', $event->transport);
        self::assertSame(MessageInterface::PROTOCOL_VERSION->value, $event->protocolVersion);
        self::assertNotNull($event->sessionId);
    }

    public function testInitializedNotificationPublishesInitializedEvent(): void
    {
        $server = $this->buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $notification = json_encode(
            new InitializedNotification(),
            JSON_THROW_ON_ERROR,
        );
        McpTestHelper::postJson($server, $notification, ['Mcp-Session-Id' => $sessionId]);

        $event = $this->onlyCaptured(McpInitializedEvent::class);
        self::assertSame($sessionId, $event->sessionId);
    }

    public function testListToolsRequestPublishesToolNames(): void
    {
        $server = $this->buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $response = McpTestHelper::postJson(
            $server,
            McpTestHelper::makeListToolsRequest('list-1'),
            ['Mcp-Session-Id' => $sessionId],
        );
        McpTestHelper::decodeResponse($response);

        $event = $this->onlyCaptured(McpToolsListEvent::class);
        self::assertSame([], $event->toolNames);
        self::assertSame($sessionId, $event->sessionId);
    }

    public function testOtherHandledRequestPublishesGenericEvent(): void
    {
        $server = $this->buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $request = (new PingRequest())->withId('ping-1');

        $response = McpTestHelper::postJson(
            $server,
            json_encode($request, JSON_THROW_ON_ERROR),
            ['Mcp-Session-Id' => $sessionId],
        );
        McpTestHelper::decodeResponse($response);

        $matches = array_values(array_filter(
            $this->captured,
            static fn (McpServerEvent $event): bool => $event->method === PingRequest::getMethod(),
        ));

        self::assertCount(1, $matches);
        self::assertSame(McpServerEvent::class, $matches[0]::class);
        self::assertSame($sessionId, $matches[0]->sessionId);
    }

    /**
     * The modern era has no PSR-14 seam — its dispatcher takes no event
     * dispatcher at all — so tool activity there is observable only because the
     * publishing decorators sit in the handler chain both eras share.
     */
    public function testModernEraToolCallPublishesToolCallEvent(): void
    {
        $server = $this->buildServer([new StubMcpTool('demo_tool')]);

        $response = McpTestHelper::postModern(
            $server,
            'tools/call',
            ['name' => 'demo_tool', 'arguments' => ['value' => 'x']],
            'modern-call-1',
        );

        self::assertSame(200, $response->getStatusCode());

        $event = $this->onlyCaptured(McpToolCallEvent::class);
        self::assertSame('demo_tool', $event->toolName);
        self::assertFalse($event->isError);
        // No handshake means no session to report; the client re-declares itself per request.
        self::assertNull($event->sessionId);
        self::assertSame('http', $event->transport);
        self::assertSame('test-client', $event->clientName);
        self::assertSame('1.0.0', $event->clientVersion);
    }

    public function testModernEraListToolsPublishesToolNames(): void
    {
        $server = $this->buildServer([new StubMcpTool('demo_tool')]);

        $response = McpTestHelper::postModern($server, 'tools/list', [], 'modern-list-1');

        self::assertSame(200, $response->getStatusCode());

        $event = $this->onlyCaptured(McpToolsListEvent::class);
        self::assertSame(['demo_tool'], $event->toolNames);
        self::assertNull($event->sessionId);
    }

    /**
     * Build a server with an empty tool set so the wiring is exercised without depending on the
     * shipped tools; the fixed tools provider bypasses container resolution.
     *
     * @param list<\Piwik\Plugins\McpServer\Contracts\McpTool> $tools
     */
    private function buildServer(array $tools = []): Server
    {
        $factory = new McpServerFactory(
            $this->createMock(LoggerInterface::class),
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider($tools),
        );

        return $factory->createServer();
    }

    /**
     * @template T of McpServerEvent
     * @param class-string<T> $expectedType
     * @return T
     */
    private function onlyCaptured(string $expectedType): McpServerEvent
    {
        $matches = array_values(array_filter(
            $this->captured,
            static fn (McpServerEvent $event): bool => $event instanceof $expectedType,
        ));

        self::assertCount(1, $matches, sprintf('Expected exactly one %s to be published.', $expectedType));

        return $matches[0];
    }
}
