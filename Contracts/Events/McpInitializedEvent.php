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
 * The client sent the `notifications/initialized` notification, confirming it finished the
 * handshake. Sessions that never send it can be told apart from compliant ones.
 *
 * Handshake-era only: MCP revision `2026-07-28` removed the notification along with the handshake
 * it confirms.
 */
final class McpInitializedEvent extends McpServerEvent
{
    public function __construct(?string $sessionId)
    {
        parent::__construct(self::METHOD_INITIALIZED, $sessionId);
    }
}
