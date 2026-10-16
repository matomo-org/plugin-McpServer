<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Events;

/**
 * The client explicitly terminated its session by sending an HTTP DELETE to the streamable-HTTP
 * endpoint (the MCP session-termination signal). Lets subscribers record a precise session-end
 * time; sessions whose client just stops (the spec only says clients SHOULD send DELETE) never
 * emit this, so consumers must fall back to a last-activity/inactivity measure.
 *
 * Unlike the other events this is not a JSON-RPC method observed on the SDK's PSR-14 seam — the
 * DELETE never reaches that seam — so it is published from the HTTP entry point instead (see
 * {@see \Piwik\Plugins\McpServer\API::mcp()}).
 *
 * Handshake-era only: MCP revision `2026-07-28` has no session to terminate.
 */
final class McpSessionEndEvent extends McpServerEvent
{
    public function __construct(?string $sessionId)
    {
        parent::__construct(self::METHOD_SESSION_END, $sessionId);
    }
}
