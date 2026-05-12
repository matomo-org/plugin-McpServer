<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Access;

/**
 * Signals that the MCP server is currently unavailable to callers — e.g.
 * disabled site-wide via SystemSettings::enableMcp. Distinct from
 * NoAccessException (user lacks permission) and BadRequestException (request
 * shape is wrong): the requester is properly authenticated and the request
 * is well-formed, the feature is simply switched off.
 *
 * The HTTP mcp() entry point catches this and shapes it into a 403 response;
 * in-process consumers receive it as a plain exception alongside Matomo's
 * standard access/error types so they can branch on the unavailable case
 * without sniffing message strings.
 */
class McpUnavailableException extends \Exception
{
}
