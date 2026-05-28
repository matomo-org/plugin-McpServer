<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Framework;

use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\McpToolsProviderInterface;

/**
 * McpToolsProvider test double that returns a caller-supplied tool set.
 *
 * The real provider resolves built-in tools from the container and fires the
 * {@hook McpServer.addTools}/{@hook McpServer.filterTools} events. Unit tests
 * that exercise {@see \Piwik\Plugins\McpServer\McpServerFactory} in isolation
 * only care about the resulting tool list, so they implement the provider
 * contract directly instead of wiring a container that resolves every built-in.
 */
final class FixedMcpToolsProvider implements McpToolsProviderInterface
{
    /**
     * @param list<McpTool> $tools
     */
    public function __construct(private array $tools)
    {
    }

    /**
     * @return list<McpTool>
     */
    public function getAllTools(): array
    {
        return $this->tools;
    }
}
