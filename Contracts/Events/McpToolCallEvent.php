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
 * An MCP `tools/call` request completed: a client invoked a tool and received a result.
 */
final class McpToolCallEvent extends McpServerEvent
{
    public function __construct(
        ?string $sessionId,
        public readonly string $toolName,
        public readonly bool $isError,
        public readonly int $durationMs,
        public readonly ?int $errorCode = null,
    ) {
        parent::__construct(self::METHOD_TOOLS_CALL, $sessionId);
    }
}
