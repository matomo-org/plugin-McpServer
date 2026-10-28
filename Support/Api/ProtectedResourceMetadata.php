<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Url;

/**
 * Builds the OAuth 2.0 Protected Resource Metadata document (RFC 9728) for the
 * MCP endpoint and the `WWW-Authenticate` challenge that points MCP clients to it.
 *
 * The document is served from a query-string URL and advertised through the
 * `resource_metadata` challenge parameter (RFC 9728 section 5.1), so discovery
 * needs no web server rewrite rules. It is only advertised while the OAuth2
 * plugin is activated, since that plugin is the authorization server it names.
 *
 * @link https://datatracker.ietf.org/doc/html/rfc9728
 */
class ProtectedResourceMetadata
{
    public const CONTROLLER_ACTION = 'protectedResourceMetadata';

    private const CHALLENGE = 'Bearer realm="mcp"';

    public function __construct(private SystemSettings $systemSettings)
    {
    }

    public function isAvailable(): bool
    {
        return $this->systemSettings->isOAuth2PluginEnabled();
    }

    /**
     * @return array{resource: string, authorization_servers: list<string>, bearer_methods_supported: list<string>}
     */
    public function build(): array
    {
        $baseUrl = $this->getBaseUrl();

        return [
            'resource' => $baseUrl . '/index.php?module=' . McpEndpointSpec::MODULE
                . '&method=' . McpEndpointSpec::METHOD . '&format=' . McpEndpointSpec::FORMAT,
            // The OAuth2 plugin reports the Matomo base URL of the current request as its
            // issuer, so the same derivation here keeps both documents in agreement.
            'authorization_servers' => [$baseUrl],
            'bearer_methods_supported' => ['header'],
        ];
    }

    public function getMetadataUrl(): string
    {
        return $this->getBaseUrl() . '/index.php?module=McpServer&action=' . self::CONTROLLER_ACTION;
    }

    public function getWwwAuthenticateHeader(): string
    {
        if (!$this->isAvailable()) {
            return self::CHALLENGE;
        }

        return self::CHALLENGE . ', resource_metadata="' . $this->getMetadataUrl() . '"';
    }

    protected function getBaseUrl(): string
    {
        return rtrim(Url::getCurrentUrlWithoutFileName(), '/');
    }
}
