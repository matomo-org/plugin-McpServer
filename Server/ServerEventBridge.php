<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Server;

use Matomo\Dependencies\McpServer\Mcp\Event\ErrorEvent;
use Matomo\Dependencies\McpServer\Mcp\Event\NotificationEvent;
use Matomo\Dependencies\McpServer\Mcp\Event\RequestEvent;
use Matomo\Dependencies\McpServer\Mcp\Event\ResponseEvent;
use Matomo\Dependencies\McpServer\Mcp\Schema\Notification\InitializedNotification;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\InitializeRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ListToolsRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\ListToolsResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Piwik\Log\LoggerInterface;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Contracts\Events\McpInitializedEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpInitializeEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpServerEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolCallEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolsListEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Wires into the bundled MCP SDK via the server builder's PSR-14 seam and publishes one neutral
 * {@see McpServerEvent} for each completed request and received notification on the
 * `McpServer.serverEvent` Matomo event.
 *
 * This is the single place that knows the SDK's event/schema types: it translates them into the
 * plugin-owned, immutable {@see McpServerEvent} contract so subscribers depend only on that
 * stable type. Every path is guarded — a failing or missing subscriber must never disrupt the
 * MCP response.
 */
final class ServerEventBridge implements EventDispatcherInterface
{
    /**
     * Transport of the public endpoint these events originate from. The bundled server is only
     * reachable over streamable HTTP, so this is constant for now.
     */
    private const TRANSPORT = 'http';

    /**
     * Request id => start microtime, used to derive the tool-call duration.
     *
     * @var array<string, float>
     */
    private array $startTimes = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function dispatch(object $event): object
    {
        try {
            if ($event instanceof RequestEvent) {
                $request = $event->getRequest();
                if ($request instanceof CallToolRequest) {
                    $this->startTimes[(string) $request->getId()] = microtime(true);
                }
            } elseif ($event instanceof ResponseEvent) {
                $this->onResponse($event);
            } elseif ($event instanceof NotificationEvent) {
                $this->onNotification($event);
            } elseif ($event instanceof ErrorEvent) {
                $this->onError($event);
            }
        } catch (\Throwable $e) {
            // Observability must never disrupt the MCP response.
            $this->logger->debug('McpServer observability bridge skipped an event: ' . $e->getMessage());
        }

        return $event;
    }

    private function onResponse(ResponseEvent $event): void
    {
        $request   = $event->getRequest();
        $result    = $event->getResponse()->result;
        $sessionId = $this->sessionId($event->getSession());

        if ($request instanceof InitializeRequest) {
            self::publishEvent(new McpInitializeEvent(
                $sessionId,
                $request->clientInfo->name,
                $request->clientInfo->version,
                $request->protocolVersion,
                self::TRANSPORT,
            ));
        } elseif ($request instanceof ListToolsRequest && $result instanceof ListToolsResult) {
            $toolNames = array_map(static fn ($tool): string => $tool->name, $result->tools);
            self::publishEvent(new McpToolsListEvent($sessionId, array_values($toolNames)));
        } elseif ($request instanceof CallToolRequest && $result instanceof CallToolResult) {
            self::publishEvent(new McpToolCallEvent(
                $sessionId,
                $request->name,
                $result->isError,
                $this->takeDurationMs($request->getId()),
            ));
        } else {
            unset($this->startTimes[(string) $request->getId()]);
            self::publishEvent(new McpServerEvent($event->getMethod(), $sessionId));
        }
    }

    private function onNotification(NotificationEvent $event): void
    {
        $sessionId = $this->sessionId($event->getSession());

        if ($event->getNotification() instanceof InitializedNotification) {
            self::publishEvent(new McpInitializedEvent($sessionId));
            return;
        }

        self::publishEvent(new McpServerEvent($event->getMethod(), $sessionId));
    }

    private function onError(ErrorEvent $event): void
    {
        $request = $event->getRequest();
        if ($request instanceof CallToolRequest) {
            self::publishEvent(new McpToolCallEvent(
                $this->sessionId($event->getSession()),
                $request->name,
                true,
                $this->takeDurationMs($request->getId()),
                $event->getError()->code,
            ));
            return;
        }

        // Drop any pending timing for this request so the map does not retain stale entries.
        unset($this->startTimes[(string) $request->getId()]);
        self::publishEvent(new McpServerEvent($request::getMethod(), $this->sessionId($event->getSession())));
    }

    /**
     * Public so endpoint code outside the SDK event path can publish through the same documented
     * Matomo event call instead of duplicating `McpServer.serverEvent` documentation.
     */
    public static function publishEvent(McpServerEvent $event): void
    {
        /**
         * Triggered once for each completed MCP request, received MCP notification, and explicit
         * session termination handled by the public endpoint.
         *
         * Successful and failed JSON-RPC requests and notifications observed through the bundled
         * SDK are published here. The streamable-HTTP DELETE session-termination signal is also
         * published after the SDK accepts it, because it does not cross the SDK event seam.
         *
         * Lets other plugins observe MCP usage without depending on the bundled MCP SDK: the
         * payload is an immutable, plugin-owned {@see McpServerEvent}. Selected methods use richer
         * subclasses with method-specific data; all other methods use the base event. Subscribers
         * must ignore event types they do not recognise, so coverage can expand without breaking
         * them.
         *
         * Usage example:
         *
         *     Piwik::addAction('McpServer.serverEvent', static function (McpServerEvent $event): void {
         *         if ($event instanceof McpToolCallEvent && $event->isError) {
         *             // Record a failed tool call.
         *         }
         *     });
         *
         * @param McpServerEvent $event Immutable description of the observed interaction.
         */
        Piwik::postEvent('McpServer.serverEvent', [$event]);
    }

    private function sessionId(SessionInterface $session): ?string
    {
        try {
            return $session->getId()->toRfc4122();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param string|int $requestId
     */
    private function takeDurationMs($requestId): int
    {
        $key   = (string) $requestId;
        $start = $this->startTimes[$key] ?? null;
        unset($this->startTimes[$key]);

        if ($start === null) {
            return 0;
        }

        return (int) round((microtime(true) - $start) * 1000);
    }
}
