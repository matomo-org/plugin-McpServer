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
 * Immutable, SDK-agnostic description of one observed MCP server interaction.
 *
 * Posted on the `McpServer.serverEvent` event so other plugins can observe MCP usage without
 * depending on the bundled MCP SDK. This base type is the stable
 * contract: it carries only the discriminator ({@see $method}) and the session id common to
 * every interaction. Selected methods expose richer data through subclasses in this namespace
 * (e.g. {@see McpToolCallEvent}); other methods use this base event directly.
 *
 * The contract is forward-open by design:
 *  - `$method` is an open string — the constants below are hints, not an exhaustive set.
 *  - New MCP methods can use this base event or a new subclass. New fields are added as
 *    trailing-optional properties. Both approaches are backward compatible.
 *  - Observers MUST ignore methods/types they do not recognise (default to a no-op), so the
 *    server can broaden coverage without breaking existing listeners.
 *
 * Subclasses only ever expose neutral scalars, so a subscriber can never reach (or mutate) the
 * live SDK request/response/session objects.
 *
 * ## What is observable per protocol revision
 *
 * The endpoint serves two MCP lifecycles, and they do not expose the same surface:
 *
 *  - Through revision `2025-11-25`, a client opens a session with `initialize` and every request
 *    on it is observable: the lifecycle events, tool activity, and the generic event for any other
 *    method.
 *  - From revision `2026-07-28` on, there is no handshake and no session. Tool activity
 *    ({@see McpToolCallEvent}, {@see McpToolsListEvent}) is published as before; the session
 *    lifecycle events are not, because the lifecycle they describe does not exist, and neither is
 *    a generic event for that revision's other methods, which the MCP SDK answers before this
 *    plugin sees them.
 *
 * So a subscriber must not require an {@see McpInitializeEvent} before it will count tool
 * activity, and must not assume tool activity is eventually followed by an
 * {@see McpSessionEndEvent}.
 *
 * ## Session id
 *
 * Null whenever the request had no session to report, which is every request on `2026-07-28`.
 * Do not treat it as a client identity or a grouping key without checking for null first: on that
 * revision the client identifies itself per request instead, and {@see McpToolCallEvent} carries
 * that identity ({@see McpToolCallEvent::$clientName}) in the session id's place.
 */
class McpServerEvent
{
    public const METHOD_INITIALIZE  = 'initialize';
    public const METHOD_INITIALIZED = 'notifications/initialized';
    public const METHOD_TOOLS_LIST  = 'tools/list';
    public const METHOD_TOOLS_CALL  = 'tools/call';
    public const METHOD_SESSION_END = 'session/end';

    public function __construct(
        public readonly string $method,
        public readonly ?string $sessionId,
    ) {
    }
}
