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
 * An MCP `initialize` request completed: a client established a session and announced itself.
 */
final class McpInitializeEvent extends McpServerEvent
{
    public function __construct(
        ?string $sessionId,
        public readonly string $clientName,
        public readonly string $clientVersion,
        public readonly string $protocolVersion,
        public readonly string $transport,
    ) {
        parent::__construct(self::METHOD_INITIALIZE, $sessionId);
    }
}
