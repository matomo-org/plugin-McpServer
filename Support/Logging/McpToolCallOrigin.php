<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Logging;

/**
 * Shared markers for tagging the origin of an MCP tool call so observability
 * can distinguish HTTP-initiated calls from in-process calls coming through
 * McpServer.callInternalTool.
 *
 * The marker is stored on the SDK Session (purely in-memory for internal
 * callers — see InternalToolCaller) and re-emitted as a PSR log context key
 * by ObservedCallToolHandler. HTTP-path sessions never set the marker, so the
 * resolved origin defaults to {@see ORIGIN_HTTP}.
 */
final class McpToolCallOrigin
{
    /**
     * Session::set/get key used to convey origin between InternalToolCaller
     * and ObservedCallToolHandler within a single request.
     */
    public const SESSION_KEY = 'matomo_mcp_call_origin';

    /** PSR log context key surfaced in tool-call log lines. */
    public const LOG_CONTEXT_KEY = 'mcp_call_origin';

    public const ORIGIN_INTERNAL = 'internal';
    public const ORIGIN_HTTP = 'http';
}
