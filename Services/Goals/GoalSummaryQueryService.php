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
use Piwik\NoAccessException;
use Piwik\Plugin\Manager;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class GoalSummaryQueryService implements GoalSummaryQueryServiceInterface
{
    /**
     * @return array<int, GoalSummaryRecord>
     */
    public function getGoalSummariesForSite(int $idSite): array
    {
        if (!Manager::getInstance()->isPluginActivated('Goals')) {
            throw new ToolCallException('Goals plugin is not available.');
        }

        try {
            $goals = GoalsApi::getInstance()->getGoals((string) $idSite, true);
        } catch (NoAccessException $e) {
            // Keep goal list behavior aligned with site/segment list: no view access yields no rows.
            return [];
        } catch (\Throwable $e) {
            if (ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
                // Compatibility fallback for no-access backends that do not throw NoAccessException.
                return [];
            }

            throw new ToolCallException('Goal retrieval failed.');
        }

        return $this->normalizeGoalSummaryRows(
            $goals,
            'Goal list data is invalid.',
            'Goal list item'
        );
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $goal
     */
    public function normalizeGoalSummaryData(array $goal, string $context): GoalSummaryRecord
    {
        return new GoalSummaryRecord(
            idGoal: ToolDataNormalizer::requireIntLikeField($goal, 'idgoal', $context),
            idSite: ToolDataNormalizer::requireIntLikeField($goal, 'idsite', $context),
            name: ToolDataNormalizer::requireStringField($goal, 'name', $context),
            description: ToolDataNormalizer::requireStringField($goal, 'description', $context),
            matchAttribute: ToolDataNormalizer::requireStringField($goal, 'match_attribute', $context),
            allowMultiple: ToolDataNormalizer::requireBoolLikeField($goal, 'allow_multiple', $context),
            revenue: GoalRevenueNormalizer::normalizeRevenue($goal, $context),
            eventValueAsRevenue: ToolDataNormalizer::requireBoolLikeField($goal, 'event_value_as_revenue', $context),
        );
    }

    /**
     * Public for testability and to keep top-level payload-shape validation centralized.
     *
     * @param mixed $goals
     * @return array<int, GoalSummaryRecord>
     */
    public function normalizeGoalSummaryRows(
        mixed $goals,
        string $invalidDataMessage,
        string $context
    ): array {
        if (!is_array($goals)) {
            throw new ToolCallException($invalidDataMessage);
        }

        $result = [];
        foreach ($goals as $goal) {
            if (!is_array($goal)) {
                throw new ToolCallException($invalidDataMessage);
            }
            $result[] = $this->normalizeGoalSummaryData($goal, $context);
        }

        return $result;
    }
}
