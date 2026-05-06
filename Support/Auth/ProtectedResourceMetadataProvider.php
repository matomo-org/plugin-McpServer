<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Auth;

use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\SettingsPiwik;

class ProtectedResourceMetadataProvider
{
    private const OAUTH2_CONTROLLER_CLASS = 'Piwik\\Plugins\\OAuth2\\Controller';
    private const OAUTH2_METADATA_ACTION = 'authorizationServerMetadata';

    public function __construct(private SystemSettings $systemSettings)
    {
    }

    public function isAvailable(): bool
    {
        return $this->systemSettings->isOAuth2PluginEnabled()
            && $this->isAuthorizationServerMetadataEndpointAvailable()
            && $this->getBaseUrl() !== '';
    }

    public function getMetadataUrl(): string
    {
        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            return '';
        }

        return $baseUrl . '/index.php?module=McpServer&action=oauthProtectedResourceMetadata';
    }

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        return [
            'resource' => $this->getMcpResourceUrl(),
            'authorization_servers' => [
                $this->getAuthorizationServerIssuerUrl(),
            ],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Matomo MCP Server',
        ];
    }

    protected function isAuthorizationServerMetadataEndpointAvailable(): bool
    {
        // Static analysis sees the bundled OAuth2 Controller, but older installs may not expose the action.
        /** @phpstan-ignore function.alreadyNarrowedType */
        return method_exists(self::OAUTH2_CONTROLLER_CLASS, self::OAUTH2_METADATA_ACTION);
    }

    private function getMcpResourceUrl(): string
    {
        return $this->getBaseUrl() . '/index.php?module=API&method=McpServer.mcp&format=mcp';
    }

    private function getAuthorizationServerIssuerUrl(): string
    {
        return $this->getBaseUrl();
    }

    protected function getBaseUrl(): string
    {
        $piwikUrl = SettingsPiwik::getPiwikUrl();
        if (!is_string($piwikUrl) || $piwikUrl === '') {
            return '';
        }

        return rtrim($piwikUrl, '/');
    }
}
