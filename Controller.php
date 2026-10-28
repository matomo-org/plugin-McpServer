<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Piwik\Access;
use Piwik\Common;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Support\Api\ProtectedResourceMetadata;
use Piwik\SettingsPiwik;
use Piwik\Url;
use Piwik\View;

class Controller extends \Piwik\Plugin\ControllerAdmin
{
    public function __construct(
        private SystemSettings $systemSettings,
        private ProtectedResourceMetadata $protectedResourceMetadata,
    ) {
    }

    public function connect(): string
    {
        Piwik::checkUserIsNotAnonymous();
        Piwik::checkUserHasSomeViewAccess();

        $view = new View('@McpServer/connect');
        $this->setBasicVariablesView($view);

        $hasSuperUserAccess = Access::getInstance()->hasSuperUserAccess();

        $view->assign('isMcpEnabled', $this->systemSettings->isMcpEnabled());
        $view->assign('isOAuth2Enabled', $this->systemSettings->isOAuth2PluginEnabled());
        $view->assign('canAccessMcpSettings', $hasSuperUserAccess);
        $view->assign('canManageOAuth2Clients', $hasSuperUserAccess);
        $view->assign('mcpApiEndpoint', $this->getMcpApiEndpointUrl());
        $view->assign('mcpSettingsUrl', $this->getMcpSettingsUrl());
        $view->assign('oauth2ClientManagementUrl', $this->getOAuth2ClientManagementUrl());
        $view->assign('userSecurityUrl', $this->getUserSecurityUrl());

        return $view->render();
    }

    /**
     * Serves the OAuth 2.0 Protected Resource Metadata document (RFC 9728) for the MCP endpoint.
     *
     * This is a public endpoint. MCP clients find it through the `resource_metadata` parameter of
     * the endpoint's `401` challenge. It answers `404` while the OAuth2 plugin is not activated,
     * because there is then no authorization server for the document to name.
     */
    public function protectedResourceMetadata(): string
    {
        // This is a public, cacheable document. Matomo's normal dispatch starts a session, which
        // queues a session cookie and PHP's session cache-limiter headers. Strip those so the
        // response is cookie-free; the Cache-Control header sent below replaces the session's one.
        Common::stripHeader('Set-Cookie');
        Common::stripHeader('Expires');
        Common::stripHeader('Pragma');
        Common::sendHeader('Content-Type: application/json; charset=utf-8');

        $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $method = is_string($requestMethod) ? strtoupper($requestMethod) : 'GET';

        if ($method !== 'GET' && $method !== 'HEAD') {
            // Common::sendResponseCode() does not support 405. PHP takes the status code from an
            // HTTP/ status line whatever the SAPI.
            Common::sendHeader('HTTP/1.1 405 Method Not Allowed');
            Common::sendHeader('Allow: GET, HEAD');
            Common::sendHeader('Cache-Control: no-store');

            return (string) json_encode(['error' => 'Method not allowed.']);
        }

        if (!$this->protectedResourceMetadata->isAvailable()) {
            Common::sendResponseCode(404);
            Common::sendHeader('Cache-Control: no-store');

            return (string) json_encode(['error' => 'Not found.']);
        }

        Common::sendHeader('Cache-Control: public, max-age=3600');

        if ($method === 'HEAD') {
            return '';
        }

        return (string) json_encode($this->protectedResourceMetadata->build(), JSON_UNESCAPED_SLASHES);
    }

    private function getMcpApiEndpointUrl(): string
    {
        return $this->getBaseUrl() . '/index.php?module=API&method=McpServer.mcp&format=mcp';
    }

    private function getUserSecurityUrl(): string
    {
        return $this->buildAdminUrl([
            'module' => 'UsersManager',
            'action' => 'userSecurity',
        ]) . '#authtokens';
    }

    private function getMcpSettingsUrl(): string
    {
        return $this->buildAdminUrl([
            'module' => 'CoreAdminHome',
            'action' => 'generalSettings',
        ]) . '#/McpServer';
    }

    private function getOAuth2ClientManagementUrl(): string
    {
        return $this->buildAdminUrl([
            'module' => 'OAuth2',
            'action' => 'index',
        ]);
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function buildAdminUrl(array $parameters): string
    {
        return 'index.php' . Url::getCurrentQueryStringWithParametersModified($parameters);
    }

    private function getBaseUrl(): string
    {
        return rtrim((string) SettingsPiwik::getPiwikUrl(), '/');
    }
}
