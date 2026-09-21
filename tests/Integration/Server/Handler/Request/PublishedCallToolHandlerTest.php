<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\Server\Handler\Request;

use Matomo\Dependencies\McpServer\Mcp\Schema\ClientCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent;
use Matomo\Dependencies\McpServer\Mcp\Schema\Enum\ProtocolVersion;
use Matomo\Dependencies\McpServer\Mcp\Schema\Implementation;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ListToolsRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\Session;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Stateless\RequestMeta;
use Matomo\Dependencies\McpServer\Symfony\Component\Uid\Uuid;
use Piwik\Log\LoggerInterface;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Contracts\Events\McpServerEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolCallEvent;
use Piwik\Plugins\McpServer\Server\Handler\Request\PublishedCallToolHandler;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Runs as an integration test so the real {@see Piwik::postEvent()} dispatcher
 * is available; the framework resets registered observers between tests.
 *
 * @group McpServer
 * @group Plugins
 */
class PublishedCallToolHandlerTest extends IntegrationTestCase
{
    private const SESSION_UUID = '87f14d0f-7d95-4a76-b2db-bf0f1ca6f3a1';

    /** @var list<McpServerEvent> */
    private array $captured = [];

    public function setUp(): void
    {
        parent::setUp();

        $this->captured = [];
        Piwik::addAction('McpServer.serverEvent', function (McpServerEvent $event): void {
            $this->captured[] = $event;
        });
    }

    public function testHandlesOnlyCallToolRequests(): void
    {
        $handler = $this->handler($this->respondWith(new CallToolResult([new TextContent('ok')])));

        self::assertTrue($handler->supports((new CallToolRequest('demo', []))->withId('1')));
        self::assertFalse($handler->supports((new ListToolsRequest())->withId('1')));
    }

    public function testPublishesToolCallForHandshakeSession(): void
    {
        $handler = $this->handler($this->respondWith(new CallToolResult([new TextContent('ok')])));

        $handler->handle($this->callRequest('report_get'), $this->handshakeSession());

        $event = $this->singleCaptured();
        self::assertSame(McpServerEvent::METHOD_TOOLS_CALL, $event->method);
        self::assertSame('report_get', $event->toolName);
        self::assertFalse($event->isError);
        self::assertNull($event->errorCode);
        self::assertGreaterThanOrEqual(0, $event->durationMs);
        // The session identifies the caller here; the initialize event for that
        // session already carries the transport and client identity.
        self::assertSame(self::SESSION_UUID, $event->sessionId);
        self::assertNull($event->transport);
        self::assertNull($event->clientName);
    }

    /**
     * A modern-era request has no session, and the SDK hands the handler a
     * throwaway one whose id would read as a distinct single-call client. The
     * per-request client declaration takes its place.
     */
    public function testPublishesToolCallForModernEraRequestWithoutSessionId(): void
    {
        $handler = $this->handler($this->respondWith(new CallToolResult([new TextContent('ok')])));

        $handler->handle($this->callRequest('report_get'), $this->modernSession());

        $event = $this->singleCaptured();
        self::assertNull($event->sessionId);
        self::assertSame('http', $event->transport);
        self::assertSame('acme-client', $event->clientName);
        self::assertSame('3.1.4', $event->clientVersion);
    }

    public function testPublishesToolCallReportedAsErrorByTheTool(): void
    {
        $handler = $this->handler($this->respondWith(new CallToolResult([new TextContent('nope')], true)));

        $handler->handle($this->callRequest('report_get'), $this->handshakeSession());

        $event = $this->singleCaptured();
        self::assertTrue($event->isError);
        self::assertNull($event->errorCode);
    }

    public function testPublishesProtocolErrorWithItsCode(): void
    {
        $error = new JsonRpcError('call-1', JsonRpcError::INVALID_PARAMS, 'bad arguments');
        $handler = $this->handler(new class ($error) implements RequestHandlerInterface {
            public function __construct(private JsonRpcError $error)
            {
            }

            public function supports(Request $request): bool
            {
                return true;
            }

            public function handle(Request $request, SessionInterface $session): JsonRpcError
            {
                return $this->error;
            }
        });

        $result = $handler->handle($this->callRequest('report_get'), $this->handshakeSession());

        self::assertSame($error, $result);
        $event = $this->singleCaptured();
        self::assertTrue($event->isError);
        self::assertSame(JsonRpcError::INVALID_PARAMS, $event->errorCode);
    }

    public function testPublishesFailureWhenTheChainThrowsAndRethrows(): void
    {
        $handler = $this->handler(new class () implements RequestHandlerInterface {
            public function supports(Request $request): bool
            {
                return true;
            }

            public function handle(Request $request, SessionInterface $session): never
            {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $handler->handle($this->callRequest('report_get'), $this->handshakeSession());
            self::fail('Expected the delegate exception to propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        $event = $this->singleCaptured();
        self::assertTrue($event->isError);
        self::assertSame(JsonRpcError::INTERNAL_ERROR, $event->errorCode);
    }

    public function testFailingSubscriberIsSwallowedAndLogged(): void
    {
        Piwik::addAction('McpServer.serverEvent', static function (): void {
            throw new \RuntimeException('subscriber blew up');
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(self::stringContains('subscriber blew up'));

        $response = $this->respondWith(new CallToolResult([new TextContent('ok')]));
        $handler = new PublishedCallToolHandler($response, $logger);

        // Observability must never disrupt the MCP response: the result still comes back.
        $result = $handler->handle($this->callRequest('report_get'), $this->handshakeSession());

        self::assertInstanceOf(Response::class, $result);
    }

    /**
     * @param RequestHandlerInterface<mixed> $delegate
     */
    private function handler(RequestHandlerInterface $delegate): PublishedCallToolHandler
    {
        return new PublishedCallToolHandler($delegate, $this->createMock(LoggerInterface::class));
    }

    /**
     * @return RequestHandlerInterface<mixed>
     */
    private function respondWith(CallToolResult $result): RequestHandlerInterface
    {
        return new class ($result) implements RequestHandlerInterface {
            public function __construct(private CallToolResult $result)
            {
            }

            public function supports(Request $request): bool
            {
                return true;
            }

            /**
             * @return Response<mixed>
             */
            public function handle(Request $request, SessionInterface $session): Response
            {
                return new Response($request->getId(), $this->result);
            }
        };
    }

    private function callRequest(string $toolName): CallToolRequest
    {
        return (new CallToolRequest($toolName, []))->withId('call-1');
    }

    private function handshakeSession(): Session
    {
        return new Session(new InMemorySessionStore(), Uuid::fromString(self::SESSION_UUID));
    }

    private function modernSession(): Session
    {
        $session = $this->handshakeSession();
        $session->set(RequestMeta::class, new RequestMeta(
            ProtocolVersion::V2026_07_28->value,
            new ClientCapabilities(),
            new Implementation('acme-client', '3.1.4'),
        ));

        return $session;
    }

    private function singleCaptured(): McpToolCallEvent
    {
        self::assertCount(1, $this->captured, 'Expected exactly one published McpServerEvent.');
        self::assertInstanceOf(McpToolCallEvent::class, $this->captured[0]);

        return $this->captured[0];
    }
}
