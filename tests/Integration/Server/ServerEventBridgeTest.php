<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\Server;

use Matomo\Dependencies\McpServer\Mcp\Event\ErrorEvent;
use Matomo\Dependencies\McpServer\Mcp\Event\NotificationEvent;
use Matomo\Dependencies\McpServer\Mcp\Event\RequestEvent;
use Matomo\Dependencies\McpServer\Mcp\Event\ResponseEvent;
use Matomo\Dependencies\McpServer\Mcp\Schema\ClientCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent;
use Matomo\Dependencies\McpServer\Mcp\Schema\Implementation;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Notification\InitializedNotification;
use Matomo\Dependencies\McpServer\Mcp\Schema\Notification\RootsListChangedNotification;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\InitializeRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ListToolsRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\PingRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\ListToolsResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\Session;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Matomo\Dependencies\McpServer\Symfony\Component\Uid\Uuid;
use Piwik\Log\LoggerInterface;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Contracts\Events\McpInitializedEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpInitializeEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpServerEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolCallEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolsListEvent;
use Piwik\Plugins\McpServer\Server\ServerEventBridge;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * Exercises {@see ServerEventBridge::dispatch()} with hand-built SDK events and asserts the
 * neutral {@see McpServerEvent} it re-publishes on the `McpServer.serverEvent` Matomo event.
 *
 * Runs as an integration test so the real {@see Piwik::postEvent()} dispatcher is available;
 * the framework resets registered observers between tests, so the capture observer added in
 * {@see setUp()} cannot leak into other test classes.
 *
 * @group McpServer
 * @group Plugins
 */
class ServerEventBridgeTest extends IntegrationTestCase
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

    public function testDispatchReturnsTheSameEventInstance(): void
    {
        $bridge = $this->bridge();
        $event = new RequestEvent(
            $this->request(new CallToolRequest('demo', []), 'r-1'),
            $this->session(),
        );

        self::assertSame($event, $bridge->dispatch($event));
    }

    public function testInitializeResponsePublishesInitializeEvent(): void
    {
        $request = $this->request(
            new InitializeRequest('2025-03-26', new ClientCapabilities(), new Implementation('acme-client', '3.1.4')),
            'init-1',
        );

        $this->bridge()->dispatch($this->responseEvent($request, null, $this->session()));

        $event = $this->singleCaptured(McpInitializeEvent::class);
        self::assertSame(McpServerEvent::METHOD_INITIALIZE, $event->method);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
        self::assertSame('acme-client', $event->clientName);
        self::assertSame('3.1.4', $event->clientVersion);
        self::assertSame('2025-03-26', $event->protocolVersion);
        self::assertSame('http', $event->transport);
    }

    public function testListToolsResponsePublishesToolNames(): void
    {
        $result = new ListToolsResult([
            $this->tool('alpha'),
            $this->tool('beta'),
        ]);
        $request = $this->request(new ListToolsRequest(), 'list-1');

        $this->bridge()->dispatch($this->responseEvent($request, $result, $this->session()));

        $event = $this->singleCaptured(McpToolsListEvent::class);
        self::assertSame(McpServerEvent::METHOD_TOOLS_LIST, $event->method);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
        self::assertSame(['alpha', 'beta'], $event->toolNames);
    }

    public function testListToolsResponseWithUnexpectedResultPublishesGenericEvent(): void
    {
        $request = $this->request(new ListToolsRequest(), 'list-2');

        $this->bridge()->dispatch($this->responseEvent($request, null, $this->session()));

        $event = $this->singleCaptured(McpServerEvent::class);
        self::assertSame(McpServerEvent::class, $event::class);
        self::assertSame(McpServerEvent::METHOD_TOOLS_LIST, $event->method);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
    }

    public function testOtherSuccessfulRequestPublishesGenericEvent(): void
    {
        $request = $this->request(new PingRequest(), 'ping-1');

        $this->bridge()->dispatch($this->responseEvent($request, null, $this->session()));

        $event = $this->singleCaptured(McpServerEvent::class);
        self::assertSame(McpServerEvent::class, $event::class);
        self::assertSame(PingRequest::getMethod(), $event->method);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
    }

    public function testCallToolResponsePublishesToolCallEventWithMeasuredDuration(): void
    {
        $bridge = $this->bridge();
        $session = $this->session();
        $request = $this->request(new CallToolRequest('report_get', ['id' => 1]), 'call-1');

        // The RequestEvent records the start time; the ResponseEvent derives the duration from it.
        $bridge->dispatch(new RequestEvent($request, $session));
        $bridge->dispatch($this->responseEvent($request, $this->callToolResult(false), $session));

        $event = $this->singleCaptured(McpToolCallEvent::class);
        self::assertSame(McpServerEvent::METHOD_TOOLS_CALL, $event->method);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
        self::assertSame('report_get', $event->toolName);
        self::assertFalse($event->isError);
        self::assertGreaterThanOrEqual(0, $event->durationMs);
        self::assertNull($event->errorCode);
    }

    public function testCallToolResponseReflectsErrorResult(): void
    {
        $request = $this->request(new CallToolRequest('report_get', []), 'call-err');

        $this->bridge()->dispatch($this->responseEvent($request, $this->callToolResult(true), $this->session()));

        $event = $this->singleCaptured(McpToolCallEvent::class);
        self::assertTrue($event->isError);
        self::assertNull($event->errorCode);
    }

    public function testCallToolResponseWithoutPriorRequestReportsZeroDuration(): void
    {
        // No preceding RequestEvent means no recorded start time, so duration falls back to 0.
        $request = $this->request(new CallToolRequest('report_get', []), 'orphan-1');

        $this->bridge()->dispatch($this->responseEvent($request, $this->callToolResult(false), $this->session()));

        $event = $this->singleCaptured(McpToolCallEvent::class);
        self::assertSame(0, $event->durationMs);
    }

    public function testRequestEventPublishesNothing(): void
    {
        // A RequestEvent only records timing; nothing is published until the response arrives.
        $request = $this->request(new CallToolRequest('report_get', []), 'req-only');

        $this->bridge()->dispatch(new RequestEvent($request, $this->session()));

        self::assertSame([], $this->captured);
    }

    public function testToolErrorEventPublishesToolCallErrorAndDropsPendingTiming(): void
    {
        $bridge = $this->bridge();
        $session = $this->session();
        $request = $this->request(new CallToolRequest('report_get', []), 'err-1');
        $error = new JsonRpcError('err-1', JsonRpcError::INTERNAL_ERROR, 'boom');

        $bridge->dispatch(new RequestEvent($request, $session));
        $bridge->dispatch(new ErrorEvent($error, $request, $session, null));

        $event = $this->singleCaptured(McpToolCallEvent::class);
        self::assertSame(McpServerEvent::METHOD_TOOLS_CALL, $event->method);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
        self::assertSame('report_get', $event->toolName);
        self::assertTrue($event->isError);
        self::assertGreaterThanOrEqual(0, $event->durationMs);
        self::assertSame(JsonRpcError::INTERNAL_ERROR, $event->errorCode);

        // The pending start time was dropped, so a late response for the same id reports 0ms.
        $bridge->dispatch($this->responseEvent($request, $this->callToolResult(true), $session));

        self::assertCount(2, $this->captured);
        self::assertInstanceOf(McpToolCallEvent::class, $this->captured[1]);
        self::assertSame(0, $this->captured[1]->durationMs);
        self::assertNull($this->captured[1]->errorCode);
    }

    public function testNonToolErrorEventPublishesGenericEvent(): void
    {
        $request = $this->request(
            new InitializeRequest('2025-03-26', new ClientCapabilities(), new Implementation('acme', '1.0')),
            'init-error',
        );
        $error = new JsonRpcError('init-error', JsonRpcError::INTERNAL_ERROR, 'boom');

        $this->bridge()->dispatch(new ErrorEvent($error, $request, $this->session(), null));

        $event = $this->singleCaptured(McpServerEvent::class);
        self::assertSame(McpServerEvent::class, $event::class);
        self::assertSame(McpServerEvent::METHOD_INITIALIZE, $event->method);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
    }

    public function testInitializedNotificationPublishesInitializedEvent(): void
    {
        $this->bridge()->dispatch(new NotificationEvent(new InitializedNotification(), $this->session()));

        $event = $this->singleCaptured(McpInitializedEvent::class);
        self::assertSame(McpServerEvent::METHOD_INITIALIZED, $event->method);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
    }

    public function testOtherNotificationPublishesGenericEvent(): void
    {
        $this->bridge()->dispatch(new NotificationEvent(new RootsListChangedNotification(), $this->session()));

        $event = $this->singleCaptured(McpServerEvent::class);
        self::assertSame(McpServerEvent::class, $event::class);
        self::assertSame(RootsListChangedNotification::getMethod(), $event->method);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
    }

    public function testSessionIdIsNullWhenSessionIdCannotBeResolved(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willThrowException(new \RuntimeException('no id'));

        $request = $this->request(
            new InitializeRequest('2025-03-26', new ClientCapabilities(), new Implementation('acme', '1.0')),
            'init-null',
        );

        $this->bridge()->dispatch($this->responseEvent($request, null, $session));

        $event = $this->singleCaptured(McpInitializeEvent::class);
        self::assertNull($event->sessionId);
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

        $request = $this->request(
            new InitializeRequest('2025-03-26', new ClientCapabilities(), new Implementation('acme', '1.0')),
            'init-throw',
        );
        $event = $this->responseEvent($request, null, $this->session());

        // A failing subscriber must never disrupt the MCP response: dispatch still returns the event.
        self::assertSame($event, (new ServerEventBridge($logger))->dispatch($event));
    }

    private function bridge(): ServerEventBridge
    {
        return new ServerEventBridge($this->createMock(LoggerInterface::class));
    }

    private function session(string $uuid = self::SESSION_UUID): Session
    {
        return new Session(new InMemorySessionStore(), Uuid::fromString($uuid));
    }

    private function request(Request $request, string $id): Request
    {
        return $request->withId($id);
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, null, ['type' => 'object', 'properties' => [], 'required' => []], null, null);
    }

    private function callToolResult(bool $isError): CallToolResult
    {
        return new CallToolResult([new TextContent('result')], $isError);
    }

    /**
     * @param mixed $result the JSON-RPC result payload the SDK would have produced
     */
    private function responseEvent(Request $request, $result, SessionInterface $session): ResponseEvent
    {
        /** @var Response<mixed> $response */
        $response = new Response($request->getId(), $result);

        return new ResponseEvent($response, $request, $session);
    }

    /**
     * @template T of McpServerEvent
     * @param class-string<T> $expectedType
     * @return T
     */
    private function singleCaptured(string $expectedType): McpServerEvent
    {
        self::assertCount(1, $this->captured, 'Expected exactly one published McpServerEvent.');
        self::assertInstanceOf($expectedType, $this->captured[0]);

        return $this->captured[0];
    }
}
