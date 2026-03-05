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
use Piwik\View;

class Controller extends \Piwik\Plugin\ControllerAdmin
{
    public function __construct(private SystemSettings $systemSettings)
    {
    }

    public function connect(): string
    {
        Piwik::checkUserHasSomeViewAccess();

        $view = new View('@McpServer/connect');
        $this->setBasicVariablesView($view);

        $view->assign('isMcpEnabled', $this->systemSettings->isMcpEnabled());
        $view->assign('canEnableMcp', Access::getInstance()->hasSuperUserAccess());
        $view->assign('mcpApiEndpoint', $this->getMcpApiEndpointUrl());
        $view->assign('mcpSettingsUrl', $this->getMcpSettingsUrl());
        $view->assign('userSecurityUrl', $this->getUserSecurityUrl());

        return $view->render();
    }

    private function getMcpApiEndpointUrl(): string
    {
        return $this->getBaseUrl() . '/index.php?module=API&method=McpServer.mcp&format=mcp';
    }

    private function getUserSecurityUrl(): string
    {
        return $this->getBaseUrl() . '/index.php?module=UsersManager&action=userSecurity#authtokens';
    }

    private function getMcpSettingsUrl(): string
    {
        return $this->getBaseUrl() . '/index.php?module=CoreAdminHome&action=generalSettings#McpServer';
    }

    private function getBaseUrl(): string
    {
        return rtrim((string) SettingsPiwik::getPiwikUrl(), '/');
    }
}
