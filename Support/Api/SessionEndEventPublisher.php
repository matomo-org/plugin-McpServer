<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Matomo\Dependencies\McpServer\Symfony\Component\Uid\Uuid;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Contracts\Events\McpSessionEndEvent;
use Piwik\Plugins\McpServer\Server\ServerEventBridge;

final class SessionEndEventPublisher
{
    private const SESSION_HEADER = 'Mcp-Session-Id';

    public function __construct(
        private readonly SessionStoreInterface $sessionStore,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function captureExistingSessionOnDelete(ServerRequestInterface $request): ?Uuid
    {
        if (strtoupper($request->getMethod()) !== 'DELETE') {
            return null;
        }

        $sessionId = $request->getHeaderLine(self::SESSION_HEADER);
        if ($sessionId === '') {
            return null;
        }

        try {
            $uuid = Uuid::fromString($sessionId);
        } catch (\Throwable) {
            return null;
        }

        try {
            return $this->sessionStore->exists($uuid) ? $uuid : null;
        } catch (\Throwable $e) {
            $this->logger->debug('McpServer could not inspect the session before DELETE: ' . $e->getMessage());

            return null;
        }
    }

    public function publishIfSessionEnded(?Uuid $sessionId, ResponseInterface $response): void
    {
        if (
            $sessionId === null
            || $response->getStatusCode() < 200
            || $response->getStatusCode() >= 300
        ) {
            return;
        }

        try {
            if ($this->sessionStore->exists($sessionId)) {
                return;
            }

            ServerEventBridge::publishEvent(new McpSessionEndEvent($sessionId->toRfc4122()));
        } catch (\Throwable $e) {
            // Never let observability break the MCP response.
            $this->logger->debug('McpServer session-end event was not published: ' . $e->getMessage());
        }
    }
}
