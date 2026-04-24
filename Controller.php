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
use Piwik\Piwik;
use Piwik\SettingsPiwik;
use Piwik\Url;
use Piwik\View;

class Controller extends \Piwik\Plugin\ControllerAdmin
{
    public function __construct(private SystemSettings $systemSettings)
    {
    }

    public function connect(): string
    {
        Piwik::checkUserIsNotAnonymous();
        Piwik::checkUserHasSomeViewAccess();

        $view = new View('@McpServer/connect');
        $this->setBasicVariablesView($view);

        $view->assign('isMcpEnabled', $this->systemSettings->isMcpEnabled());
        $view->assign('isOAuth2Enabled', $this->systemSettings->isOAuth2PluginEnabled());
        $view->assign('canAccessMcpSettings', Access::getInstance()->hasSuperUserAccess());
        $view->assign('mcpApiEndpoint', $this->getMcpApiEndpointUrl());
        $view->assign('mcpSettingsUrl', $this->getMcpSettingsUrl());
        $view->assign('oauth2ClientManagementUrl', $this->getOAuth2ClientManagementUrl());
        $view->assign('userSecurityUrl', $this->getUserSecurityUrl());

        return $view->render();
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
