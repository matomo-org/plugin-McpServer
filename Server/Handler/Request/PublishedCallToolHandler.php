<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Server\Handler\Request;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolCallEvent;
use Piwik\Plugins\McpServer\Server\ObservedCaller;
use Piwik\Plugins\McpServer\Server\ServerEventBridge;

/**
 * Publishes one {@see McpToolCallEvent} per completed `tools/call`, whichever
 * protocol era served it.
 *
 * Observability sits in the handler chain rather than on the SDK's PSR-14 event
 * seam ({@see ServerEventBridge}) because only the handshake era has that seam:
 * `StatelessProtocol` takes no event dispatcher, so a modern-era tool call
 * crosses no SDK event at all. Both eras share the request handlers, so a
 * decorator here is the one place both traverse.
 *
 * Distinct from {@see ObservedCallToolHandler}, which is registered only when
 * tool-call logging is switched on. This one is always registered: the
 * `McpServer.serverEvent` contract does not depend on a logging setting.
 *
 * @implements RequestHandlerInterface<mixed>
 */
final class PublishedCallToolHandler implements RequestHandlerInterface
{
    /**
     * @param RequestHandlerInterface<mixed> $delegate
     */
    public function __construct(
        private readonly RequestHandlerInterface $delegate,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /**
     * @return Response<mixed>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        $startedAt = microtime(true);

        try {
            $result = $this->delegate->handle($request, $session);
        } catch (\Throwable $e) {
            // An exception leaving the chain is rendered as a protocol error by
            // the dispatcher, so it is a failed call from the client's side too.
            $this->publish($request, $session, true, $startedAt, Error::INTERNAL_ERROR);

            throw $e;
        }

        if ($result instanceof Error) {
            $this->publish($request, $session, true, $startedAt, $result->code);

            return $result;
        }

        // A tool reporting its own failure answers with an isError result; a
        // result of any other type means the call did not produce tool output.
        $isError = !$result->result instanceof CallToolResult || $result->result->isError;
        $this->publish($request, $session, $isError, $startedAt);

        return $result;
    }

    private function publish(
        CallToolRequest $request,
        SessionInterface $session,
        bool $isError,
        float $startedAt,
        ?int $errorCode = null,
    ): void {
        try {
            $caller = ObservedCaller::fromSession($session);

            ServerEventBridge::publishEvent(new McpToolCallEvent(
                $caller->sessionId,
                $request->name,
                $isError,
                (int) round((microtime(true) - $startedAt) * 1000),
                $errorCode,
                $caller->transport,
                $caller->clientName,
                $caller->clientVersion,
            ));
        } catch (\Throwable $e) {
            // Observability must never disrupt the MCP response.
            $this->logger->debug('McpServer tool-call event was not published: ' . $e->getMessage());
        }
    }
}
