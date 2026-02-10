<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\Goals;

use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Goals\ListApiWrapperInterface;
use Piwik\Plugins\McpServer\Services\Goals\GoalSummaryQueryService;

final class ListApiWrapper implements ListApiWrapperInterface
{
    public function __construct(private ?GoalSummaryQueryServiceInterface $queryService = null)
    {
    }

    /**
     * @return array<int, GoalSummaryRecord>
     */
    public function getGoalsForSite(int $idSite): array
    {
        return $this->getQueryService()->getGoalSummariesForSite($idSite);
    }

    private function getQueryService(): GoalSummaryQueryServiceInterface
    {
        return $this->queryService ??= new GoalSummaryQueryService();
    }
}
