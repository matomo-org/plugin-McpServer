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
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ListToolsRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\ListToolsResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolsListEvent;
use Piwik\Plugins\McpServer\Server\ObservedCaller;
use Piwik\Plugins\McpServer\Server\ServerEventBridge;

/**
 * Publishes one {@see McpToolsListEvent} per completed `tools/list`, whichever
 * protocol era served it. See {@see PublishedCallToolHandler} for why this sits
 * in the handler chain rather than on the SDK's event seam.
 *
 * @implements RequestHandlerInterface<mixed>
 */
final class PublishedListToolsHandler implements RequestHandlerInterface
{
    /**
     * @param RequestHandlerInterface<mixed> $delegate the SDK's own list handler
     */
    public function __construct(
        private readonly RequestHandlerInterface $delegate,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof ListToolsRequest;
    }

    /**
     * @return Response<mixed>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof ListToolsRequest);

        $result = $this->delegate->handle($request, $session);

        if ($result instanceof Response && $result->result instanceof ListToolsResult) {
            $this->publish($session, $result->result);
        }

        return $result;
    }

    private function publish(SessionInterface $session, ListToolsResult $result): void
    {
        try {
            $caller = ObservedCaller::fromSession($session);
            $toolNames = array_map(static fn (Tool $tool): string => $tool->name, $result->tools);

            ServerEventBridge::publishEvent(new McpToolsListEvent($caller->sessionId, array_values($toolNames)));
        } catch (\Throwable $e) {
            // Observability must never disrupt the MCP response.
            $this->logger->debug('McpServer tools-list event was not published: ' . $e->getMessage());
        }
    }
}
