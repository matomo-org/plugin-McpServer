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
use Piwik\Plugins\McpServer\Contracts\Events\McpToolsListEvent;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Plugins\McpServer\tests\Framework\FixedMcpToolsProvider;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Psr\Container\ContainerInterface;

/**
 * Verifies that {@see McpServerFactory} registers the {@see ServerEventBridge} as the bundled
 * server's PSR-14 event dispatcher, so that driving real JSON-RPC requests through the server
 * re-publishes them on the `McpServer.serverEvent` Matomo event.
 *
 * @group McpServer
 * @group Plugins
 */
class ServerEventBridgeRegistrationTest extends IntegrationTestCase
{
    /** @var array<mixed>|null */
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
     * Build a server with an empty tool set so the wiring is exercised without depending on the
     * shipped tools; the fixed tools provider bypasses container resolution.
     */
    private function buildServer(): Server
    {
        $factory = new McpServerFactory(
            $this->createMock(LoggerInterface::class),
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
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
