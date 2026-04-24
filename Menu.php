<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Piwik\Menu\MenuAdmin;
use Piwik\Piwik;

class Menu extends \Piwik\Plugin\Menu
{
    public function configureAdminMenu(MenuAdmin $menu): void
    {
        if (!Piwik::isUserIsAnonymous() && Piwik::isUserHasSomeViewAccess()) {
            $menu->addPlatformItem('McpServer_PlatformMenu', $this->urlForAction('connect'), 42);
        }
    }
}
