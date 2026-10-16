<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Server;

use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Stateless\RequestMeta;

/**
 * Who made one MCP request, in the neutral terms the
 * {@see \Piwik\Plugins\McpServer\Contracts\Events\McpServerEvent} contract
 * publishes, resolved from whatever the serving protocol era offers.
 *
 * The two eras describe a caller differently, and an observer should be told
 * what is true rather than a uniform-looking guess:
 *
 *  - Handshake era: the client identified itself once at `initialize` and every
 *    later request belongs to the session that opened. So the session id is the
 *    caller's identity here, and the client name/version are deliberately left
 *    out — the {@see \Piwik\Plugins\McpServer\Contracts\Events\McpInitializeEvent}
 *    for that session already carries them.
 *  - Modern era (`2026-07-28`): there is no handshake and no session. The
 *    client re-declares itself on every request instead, so the identity
 *    travels with each event and there is no session id to report. Reporting
 *    one would be worse than reporting none: the SDK hands each stateless
 *    request a throwaway in-memory session with a fresh random id, which an
 *    observer grouping by session would read as a distinct one-call client.
 */
final class ObservedCaller
{
    /**
     * Transport of the public endpoint. The bundled server is only reachable
     * over streamable HTTP, so this is constant for now.
     */
    public const TRANSPORT_HTTP = 'http';

    private function __construct(
        public readonly ?string $sessionId,
        public readonly ?string $transport,
        public readonly ?string $clientName,
        public readonly ?string $clientVersion,
    ) {
    }

    public static function fromSession(SessionInterface $session): self
    {
        $meta = self::requestMeta($session);

        if ($meta === null) {
            return new self(self::sessionId($session), null, null, null);
        }

        return new self(
            null,
            self::TRANSPORT_HTTP,
            $meta->clientInfo?->name,
            $meta->clientInfo?->version,
        );
    }

    /**
     * Present only on a modern-era request: it is what the stateless dispatcher
     * puts in the throwaway session to carry that request's own declaration.
     */
    private static function requestMeta(SessionInterface $session): ?RequestMeta
    {
        try {
            $meta = $session->get(RequestMeta::class);
        } catch (\Throwable) {
            return null;
        }

        return $meta instanceof RequestMeta ? $meta : null;
    }

    private static function sessionId(SessionInterface $session): ?string
    {
        try {
            return $session->getId()->toRfc4122();
        } catch (\Throwable) {
            return null;
        }
    }
}
