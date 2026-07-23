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
 * An MCP `tools/call` request completed: a client or internal bridge invoked a tool and received
 * a result. Transport/client metadata is optional because public HTTP calls get that context from
 * initialize events, while internal calls have no protocol handshake and self-describe here
 * ({@see $transport}, {@see $clientName}, {@see $clientVersion}) instead.
 */
final class McpToolCallEvent extends McpServerEvent
{
    public function __construct(
        ?string $sessionId,
        public readonly string $toolName,
        public readonly bool $isError,
        public readonly int $durationMs,
        public readonly ?int $errorCode = null,
        public readonly ?string $transport = null,
        public readonly ?string $clientName = null,
        public readonly ?string $clientVersion = null,
    ) {
        parent::__construct(self::METHOD_TOOLS_CALL, $sessionId);
    }
}
