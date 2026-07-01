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
 * Posted on the `McpServer.serverEvent` event so other plugins can observe MCP usage on the
 * public endpoint without depending on the bundled MCP SDK. This base type is the stable
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
 */
class McpServerEvent
{
    public const METHOD_INITIALIZE  = 'initialize';
    public const METHOD_INITIALIZED = 'notifications/initialized';
    public const METHOD_TOOLS_LIST  = 'tools/list';
    public const METHOD_TOOLS_CALL  = 'tools/call';

    public function __construct(
        public readonly string $method,
        public readonly ?string $sessionId,
    ) {
    }
}
