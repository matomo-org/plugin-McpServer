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
use Matomo\Dependencies\McpServer\Mcp\Event\ResponseEvent;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\Notification\InitializedNotification;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\InitializeRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ListToolsRequest;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Piwik\Log\LoggerInterface;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Contracts\Events\McpInitializedEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpInitializeEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpServerEvent;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Wires into the bundled MCP SDK via the server builder's PSR-14 seam and publishes one neutral
 * {@see McpServerEvent} for each completed request and received notification on the
 * `McpServer.serverEvent` Matomo event. Internal MCP bridge calls publish through the same Matomo
 * event directly.
 *
 * Tool activity is not published here. Only the handshake era has this seam — the modern era's
 * dispatcher takes no event dispatcher at all — so the events worth having on both are published
 * from the handler chain both eras share, by
 * {@see \Piwik\Plugins\McpServer\Server\Handler\Request\PublishedCallToolHandler} and
 * {@see \Piwik\Plugins\McpServer\Server\Handler\Request\PublishedListToolsHandler}. This class
 * skips `tools/call` and `tools/list` so a handshake-era client does not see each of them twice.
 *
 * What is left is what only the handshake era has: the `initialize` lifecycle, and the generic
 * event for every other method. A modern-era request outside `tools/call` and `tools/list` is
 * therefore not observable — `server/discover` and `subscriptions/listen` are answered inside the
 * SDK, with no handler to decorate.
 *
 * Like those decorators, this translates the SDK's event/schema types into the plugin-owned,
 * immutable {@see McpServerEvent} contract, so subscribers depend only on that stable type and
 * never on the bundled SDK. Every path is guarded — a failing or missing subscriber must never
 * disrupt the MCP response.
 */
final class ServerEventBridge implements EventDispatcherInterface
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function dispatch(object $event): object
    {
        try {
            if ($event instanceof ResponseEvent) {
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
        $request = $event->getRequest();

        if (self::isPublishedByHandlerChain($request)) {
            return;
        }

        $sessionId = $this->sessionId($event->getSession());

        if ($request instanceof InitializeRequest) {
            self::publishEvent(new McpInitializeEvent(
                $sessionId,
                $request->clientInfo->name,
                $request->clientInfo->version,
                $request->protocolVersion,
                ObservedCaller::TRANSPORT_HTTP,
            ));
        } else {
            self::publishEvent(new McpServerEvent($event->getMethod(), $sessionId));
        }
    }

    /**
     * Whether the handler chain publishes this request's event, so that this
     * seam must not publish a second one for it.
     */
    private static function isPublishedByHandlerChain(Request $request): bool
    {
        return $request instanceof CallToolRequest || $request instanceof ListToolsRequest;
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

        if (self::isPublishedByHandlerChain($request)) {
            return;
        }

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
         * Internal tool calls use the same event with synthetic internal session ids.
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
         *         if ($event instanceof \Piwik\Plugins\McpServer\Contracts\Events\McpToolCallEvent
         *             && $event->isError
         *         ) {
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
}
