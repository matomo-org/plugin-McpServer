<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts;

/**
 * Behavioural hints attached to an McpTool, surfaced in tool listings so an
 * MCP client can reason about whether a call is read-only, destructive,
 * idempotent, or may reach beyond its declared inputs. Every field is
 * optional — null means "not declared".
 */
final class McpToolAnnotations
{
    public function __construct(
        public readonly ?bool $readOnlyHint = null,
        public readonly ?bool $destructiveHint = null,
        public readonly ?bool $idempotentHint = null,
        public readonly ?bool $openWorldHint = null,
    ) {
    }
}
