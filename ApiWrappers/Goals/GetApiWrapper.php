<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\Goals;

use Piwik\Plugins\McpServer\Contracts\Goals\GetApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalDetailRecord;
use Piwik\Plugins\McpServer\Services\Goals\GoalDetailQueryService;

final class GetApiWrapper implements GetApiWrapperInterface
{
    public function __construct(private ?GoalDetailQueryServiceInterface $queryService = null)
    {
    }

    public function getGoalById(int $idSite, int $idGoal): GoalDetailRecord
    {
        return $this->getQueryService()->getGoalDetailForSite($idSite, $idGoal);
    }

    private function getQueryService(): GoalDetailQueryServiceInterface
    {
        return $this->queryService ??= new GoalDetailQueryService();
    }
}
