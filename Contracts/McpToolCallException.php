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
 * Thrown to abort an MCP tool call with a structured error returned to the
 * MCP client. Typically raised from an McpTool subclass via $this->fail(),
 * but also thrown directly by supporting services and helpers (pagination,
 * gateways, normalisers, etc.) invoked during the tool's call chain.
 */
final class McpToolCallException extends \RuntimeException
{
}
