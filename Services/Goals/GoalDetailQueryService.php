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
use Piwik\Plugins\McpServer\Contracts\Goals\GoalDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalDetailRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class GoalDetailQueryService implements GoalDetailQueryServiceInterface
{
    public function getGoalDetailForSite(int $idSite, int $idGoal): GoalDetailRecord
    {
        if (!Manager::getInstance()->isPluginActivated('Goals')) {
            throw new ToolCallException('Goals plugin is not available.');
        }

        try {
            $goal = GoalsApi::getInstance()->getGoal($idSite, $idGoal);
        } catch (NoAccessException $e) {
            throw new ToolCallException('Goal not found.');
        } catch (\Throwable $e) {
            if (ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
                throw new ToolCallException('Goal not found.');
            }

            throw new ToolCallException('Goal retrieval failed.');
        }

        if (!is_array($goal)) {
            throw new ToolCallException('Goal not found.');
        }

        return $this->normalizeGoalDetailData($goal, 'Goal data');
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $goal
     */
    public function normalizeGoalDetailData(array $goal, string $context): GoalDetailRecord
    {
        $matchAttribute = ToolDataNormalizer::requireStringField($goal, 'match_attribute', $context);

        return new GoalDetailRecord(
            idGoal: ToolDataNormalizer::requireIntLikeField($goal, 'idgoal', $context),
            idSite: ToolDataNormalizer::requireIntLikeField($goal, 'idsite', $context),
            name: ToolDataNormalizer::requireStringField($goal, 'name', $context),
            description: ToolDataNormalizer::requireStringField($goal, 'description', $context),
            matchAttribute: $matchAttribute,
            allowMultiple: ToolDataNormalizer::requireBoolLikeField($goal, 'allow_multiple', $context),
            revenue: GoalRevenueNormalizer::normalizeRevenue($goal, $context),
            eventValueAsRevenue: ToolDataNormalizer::requireBoolLikeField($goal, 'event_value_as_revenue', $context),
            pattern: $this->normalizePattern($goal, $matchAttribute, $context),
            patternType: $this->normalizePatternType($goal, $matchAttribute, $context),
            caseSensitive: $this->normalizeCaseSensitive($goal, $matchAttribute, $context),
        );
    }

    /**
     * @param array<string, mixed> $goal
     */
    private function normalizePattern(array $goal, string $matchAttribute, string $context): ?string
    {
        if ($matchAttribute === 'manually') {
            return null;
        }

        return ToolDataNormalizer::requireStringField($goal, 'pattern', $context);
    }

    /**
     * @param array<string, mixed> $goal
     */
    private function normalizePatternType(array $goal, string $matchAttribute, string $context): ?string
    {
        if ($matchAttribute === 'manually') {
            return null;
        }

        return ToolDataNormalizer::requireStringField($goal, 'pattern_type', $context);
    }

    /**
     * @param array<string, mixed> $goal
     */
    private function normalizeCaseSensitive(array $goal, string $matchAttribute, string $context): ?bool
    {
        if ($matchAttribute === 'manually') {
            return null;
        }

        return ToolDataNormalizer::requireBoolLikeField($goal, 'case_sensitive', $context);
    }
}
