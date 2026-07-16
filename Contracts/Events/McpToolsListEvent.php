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
 * An MCP `tools/list` request completed: the client discovered the available tools.
 */
final class McpToolsListEvent extends McpServerEvent
{
    /**
     * @param list<string> $toolNames the tool names returned to the client
     */
    public function __construct(
        ?string $sessionId,
        public readonly array $toolNames,
    ) {
        parent::__construct(self::METHOD_TOOLS_LIST, $sessionId);
    }
}
