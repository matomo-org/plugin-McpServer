<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Server;

use Matomo\Dependencies\McpServer\Mcp\Capability\RegistryInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;

/**
 * Internal handle on the populated MCP runtime exposed to other Matomo
 * plugins via API.php. Holds the same registry and call-tool handler that
 * back the public HTTP endpoint, so internal in-process callers see the
 * exact same tool catalogue and execution semantics.
 *
 * Deliberately does not expose the production session store: internal callers
 * dispatch a single tool per call with an ephemeral in-memory session, so a
 * persistent session store would only be a footgun if a tool ever called
 * Session::save().
 *
 * Not part of any public SDK contract; safe to evolve alongside this plugin.
 * The constructor-promoted readonly properties are likewise internal and may
 * be reshaped without notice.
 *
 * @internal
 */
final class InternalAccess
{
    /**
     * @param RequestHandlerInterface<mixed> $callToolHandler
     */
    public function __construct(
        public readonly RegistryInterface $registry,
        public readonly RequestHandlerInterface $callToolHandler,
    ) {
    }
}
