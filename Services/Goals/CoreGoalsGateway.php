<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Goals;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Plugin\Manager;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\CoreGoalsGatewayInterface;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class CoreGoalsGateway implements CoreGoalsGatewayInterface
{
    public function isGoalsPluginActivated(): bool
    {
        return Manager::getInstance()->isPluginActivated('Goals');
    }

    public function getGoals(int $idSite): array
    {
        $goals = GoalsApi::getInstance()->getGoals((string) $idSite, true);

        return $this->normalizeRows($goals, 'Goals data is invalid.');
    }

    public function getGoal(int $idSite, int $idGoal): array
    {
        $goal = GoalsApi::getInstance()->getGoal($idSite, $idGoal);

        return ToolDataNormalizer::requireStringKeyedArray($goal, 'Goal data is invalid.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(mixed $rows, string $invalidDataMessage): array
    {
        if (!is_array($rows)) {
            throw new ToolCallException($invalidDataMessage);
        }

        if (!array_is_list($rows)) {
            $rows = array_values($rows);
        }

        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = ToolDataNormalizer::requireStringKeyedArray($row, $invalidDataMessage);
        }

        return $normalized;
    }
}
