<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Server\Transport;

use Matomo\Dependencies\McpServer\Http\Discovery\Psr17Factory;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Server\MiddlewareInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Server\RequestHandlerInterface;
use Piwik\Url;

/**
 * DNS-rebinding / cross-origin protection for the MCP endpoint, aligned with
 * Matomo's own host trust rather than the raw request headers.
 *
 * This replaces the SDK's DnsRebindingProtectionMiddleware, which validates the
 * raw `Host` header. Behind a reverse proxy that terminates on a different
 * backend name, the raw `Host` is the backend, not the client-facing host — so
 * the SDK check 403s legitimate requests while Matomo (via `proxy_host_headers`
 * → `X-Forwarded-Host`) resolves the real host fine.
 *
 * Accepting an `Origin` here is *request validation* (DNS-rebinding defense),
 * not CORS support. It mirrors the SDK's DnsRebindingProtectionMiddleware model:
 * validate the supplied `Origin`/`Host` against the allowlist of hostnames this
 * deployment answers to. In Matomo that allowlist is `[General] trusted_hosts`
 * — its SDK-relevant meaning here is "known hostnames belonging to this server
 * deployment", not a list of CORS callers. The two request shapes:
 *
 * - `Origin` present: the host is matched (exactly) against the configured
 *   trusted-host allowlist ({@see \Piwik\Plugins\McpServer\McpServerFactory}
 *   builds it). A DNS-rebinding attack arrives with the *attacker's* origin, so
 *   an origin outside the deployment's own hostnames is rejected (403); an
 *   origin that *is* a deployment host is a same-deployment request, not an
 *   attack, and continues. `Origin` is browser-set and not rewritten by proxies,
 *   so it is authoritative. An empty allowlist rejects every supplied `Origin`
 *   (fail-closed: with no trusted-host policy the deployment's own hostnames are
 *   unknown), and this branch enforces regardless of
 *   `[General] enable_trusted_host_check` — that flag governs the no-`Origin`
 *   branch, but must not waive supplied-`Origin` validation.
 * - no `Origin` (non-browser client): validated against Matomo's proxy-aware
 *   current host via {@see Url::getCurrentHost()} / {@see Url::isValidHost()},
 *   i.e. the same trust decision Matomo makes everywhere else — never the raw
 *   `Host` header. This branch stays permissive wherever {@see Url::isValidHost()}
 *   is (host check disabled, or instance not yet configured).
 *
 * The `Origin` host is matched **exactly** (case-insensitive, port-less,
 * trailing-dot-normalized), not with {@see Url::isValidHost()}'s subdomain-
 * permissive regex: a browser origin is per-host, so a subdomain of a trusted
 * host is a distinct origin and a lookalike suffix must not slip through.
 *
 * Cross-origin browser MCP remains unsupported — passing this validation is not
 * a CORS grant. We install no `CorsMiddleware`; Matomo core emits the
 * `Access-Control-Allow-Origin` response header from `[General] cors_domains` at
 * the API layer for this endpoint like every other API method (it does not emit
 * the preflight `Access-Control-Allow-Methods`/`-Headers`), and a CORS preflight
 * cannot authenticate anyway — see
 * {@see \Piwik\Plugins\McpServer\McpServerFactory::createTransport()}.
 *
 * This is defense-in-depth; the endpoint is already token-authenticated before
 * the transport runs — passing Origin validation still requires a Bearer token.
 */
final class MatomoHostValidationMiddleware implements MiddlewareInterface
{
    /** @var list<string> Normalized comparison keys ({@see hostKey()}) for the hostnames this deployment answers to. */
    private readonly array $allowedOriginHosts;

    /**
     * @param list<string> $trustedHosts Raw `[General] trusted_hosts` entries this deployment answers to (may carry a
     *                                    `:port`; case and trailing FQDN dot are insignificant). Reduced to
     *                                    comparison keys via {@see hostKey()}, the same reduction applied to a
     *                                    supplied `Origin`, so the two sides can never normalize differently; a
     *                                    supplied `Origin` host must then match one exactly. An effectively empty
     *                                    list rejects every supplied `Origin` (fail-closed)
     */
    public function __construct(array $trustedHosts)
    {
        $hosts = [];
        foreach ($trustedHosts as $trustedHost) {
            // A trusted_hosts entry is a bare host (possibly `host:port`), not a
            // URL, so prefix a protocol-relative `//` to route it through the
            // same host parser as the Origin URL. Entries with no parseable host
            // (e.g. an empty string) are dropped.
            $host = self::hostKey('//' . $trustedHost);
            if ($host !== null) {
                $hosts[] = $host;
            }
        }

        $this->allowedOriginHosts = array_values(array_unique($hosts));
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '') {
            if (!$this->isAllowedOrigin($origin)) {
                return $this->createForbiddenResponse('Forbidden: Invalid Origin header.');
            }

            return $handler->handle($request);
        }

        // No Origin: a non-browser client. Validate the request's host the way
        // Matomo does everywhere else — proxy-aware and against trusted_hosts —
        // rather than trusting the raw Host header, which is the backend name
        // behind a Host-rewriting proxy.
        $host = Url::getCurrentHost('', false);
        if ($host !== '' && !Url::isValidHost($host)) {
            return $this->createForbiddenResponse('Forbidden: Invalid Host header.');
        }

        return $handler->handle($request);
    }

    private function isAllowedOrigin(string $origin): bool
    {
        // No trusted-host policy: the deployment's own hostnames are unknown, so
        // a supplied Origin cannot be validated — fail closed.
        if ($this->allowedOriginHosts === []) {
            return false;
        }

        // Reduce the Origin to the same comparison key as the allowlist, then
        // require an exact match. A browser Origin is per-host, so a subdomain of
        // a trusted host (a distinct origin) and any lookalike suffix are both
        // rejected.
        $host = self::hostKey($origin);

        return $host !== null && in_array($host, $this->allowedOriginHosts, true);
    }

    /**
     * The single comparison key for a host, applied to both the trusted-host
     * allowlist and the incoming `Origin` so they cannot diverge: the URL's host
     * part per {@see Url::getHostFromUrl()} (port stripped, lowercased, IPv6
     * brackets kept), with a trailing FQDN dot removed. `null` when the input has
     * no parseable host.
     */
    private static function hostKey(string $url): ?string
    {
        $host = Url::getHostFromUrl($url);

        return $host === null ? null : rtrim($host, '.');
    }

    private function createForbiddenResponse(string $message): ResponseInterface
    {
        $factory = new Psr17Factory();

        return $factory->createResponse(403)
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($factory->createStream($message));
    }
}
