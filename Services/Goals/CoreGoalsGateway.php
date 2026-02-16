<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Goals;

use Piwik\Plugin\Manager;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\CoreGoalsGatewayInterface;

final class CoreGoalsGateway implements CoreGoalsGatewayInterface
{
    public function isGoalsPluginActivated(): bool
    {
        return Manager::getInstance()->isPluginActivated('Goals');
    }

    public function getGoals(int $idSite)
    {
        return GoalsApi::getInstance()->getGoals((string) $idSite, true);
    }

    public function getGoal(int $idSite, int $idGoal)
    {
        return GoalsApi::getInstance()->getGoal($idSite, $idGoal);
    }
}
