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
use Matomo\Dependencies\McpServer\Mcp\Schema\Enum\ProtocolVersion;
use Matomo\Dependencies\McpServer\Mcp\Schema\Implementation;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ListToolsRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\ListToolsResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\Session;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Stateless\RequestMeta;
use Matomo\Dependencies\McpServer\Symfony\Component\Uid\Uuid;
use Piwik\Log\LoggerInterface;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Contracts\Events\McpServerEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolsListEvent;
use Piwik\Plugins\McpServer\Server\Handler\Request\PublishedListToolsHandler;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class PublishedListToolsHandlerTest extends IntegrationTestCase
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

    public function testHandlesOnlyListToolsRequests(): void
    {
        $handler = $this->handler();

        self::assertTrue($handler->supports((new ListToolsRequest())->withId('1')));
        self::assertFalse($handler->supports((new CallToolRequest('demo', []))->withId('1')));
    }

    public function testPublishesListedToolNamesForHandshakeSession(): void
    {
        $handler = $this->handler();

        $handler->handle($this->listRequest(), $this->handshakeSession());

        $event = $this->singleCaptured();
        self::assertSame(McpServerEvent::METHOD_TOOLS_LIST, $event->method);
        self::assertSame(['alpha', 'beta'], $event->toolNames);
        self::assertSame(self::SESSION_UUID, $event->sessionId);
    }

    public function testPublishesListedToolNamesForModernEraRequestWithoutSessionId(): void
    {
        $handler = $this->handler();

        $handler->handle($this->listRequest(), $this->modernSession());

        $event = $this->singleCaptured();
        self::assertSame(['alpha', 'beta'], $event->toolNames);
        self::assertNull($event->sessionId);
    }

    public function testPublishesNothingWhenTheDelegateDoesNotListTools(): void
    {
        $handler = new PublishedListToolsHandler(
            new class () implements RequestHandlerInterface {
                public function supports(Request $request): bool
                {
                    return true;
                }

                public function handle(Request $request, SessionInterface $session): JsonRpcError
                {
                    return new JsonRpcError($request->getId(), JsonRpcError::INVALID_PARAMS, 'bad cursor');
                }
            },
            $this->createMock(LoggerInterface::class),
        );

        $result = $handler->handle($this->listRequest(), $this->handshakeSession());

        self::assertInstanceOf(JsonRpcError::class, $result);
        self::assertSame([], $this->captured);
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

        $handler = new PublishedListToolsHandler($this->listingDelegate(), $logger);

        // Observability must never disrupt the MCP response: the result still comes back.
        $result = $handler->handle($this->listRequest(), $this->handshakeSession());

        self::assertInstanceOf(Response::class, $result);
    }

    private function handler(): PublishedListToolsHandler
    {
        return new PublishedListToolsHandler($this->listingDelegate(), $this->createMock(LoggerInterface::class));
    }

    /**
     * @return RequestHandlerInterface<mixed>
     */
    private function listingDelegate(): RequestHandlerInterface
    {
        $tools = [$this->tool('alpha'), $this->tool('beta')];

        return new class ($tools) implements RequestHandlerInterface {
            /**
             * @param list<Tool> $tools
             */
            public function __construct(private array $tools)
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
                return new Response($request->getId(), new ListToolsResult($this->tools));
            }
        };
    }

    private function tool(string $name): Tool
    {
        return new Tool($name, null, ['type' => 'object', 'properties' => [], 'required' => []], null, null);
    }

    private function listRequest(): ListToolsRequest
    {
        return (new ListToolsRequest())->withId('list-1');
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

    private function singleCaptured(): McpToolsListEvent
    {
        self::assertCount(1, $this->captured, 'Expected exactly one published McpServerEvent.');
        self::assertInstanceOf(McpToolsListEvent::class, $this->captured[0]);

        return $this->captured[0];
    }
}
