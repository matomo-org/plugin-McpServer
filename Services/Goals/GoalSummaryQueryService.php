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
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\CoreGoalsGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Goals\GoalSummaryRecord;
use Piwik\Plugins\McpServer\Support\Errors\ToolErrorMapper;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class GoalSummaryQueryService implements GoalSummaryQueryServiceInterface
{
    public function __construct(
        private CoreGoalsGatewayInterface $coreGoalsGateway,
        private PluginCapabilityGatewayInterface $pluginCapabilityGateway,
    ) {
    }

    /**
     * @return array<int, GoalSummaryRecord>
     */
    public function getGoalSummariesForSite(int $idSite): array
    {
        if (!$this->pluginCapabilityGateway->isPluginActivated('Goals')) {
            throw new ToolCallException('Goals plugin is not available.');
        }

        try {
            $goals = $this->coreGoalsGateway->getGoals($idSite);
        } catch (\Throwable $e) {
            // Keep goal list behavior aligned with site/segment list: no view access yields no rows.
            if (ToolErrorMapper::shouldReturnEmptyListFor($e)) {
                return [];
            }

            throw new ToolCallException('Goal retrieval failed.');
        }

        return $this->normalizeGoalSummaryRows(
            $goals,
            'Goal list data is invalid.',
            'Goal list item',
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
     * @return array<int, GoalSummaryRecord>
     */
    public function normalizeGoalSummaryRows(
        mixed $goals,
        string $invalidDataMessage,
        string $context,
    ): array {
        if (!is_array($goals)) {
            throw new ToolCallException($invalidDataMessage);
        }

        $result = [];
        foreach ($goals as $goal) {
            $goalData = ToolDataNormalizer::requireStringKeyedArray($goal, $invalidDataMessage);
            $result[] = $this->normalizeGoalSummaryData($goalData, $context);
        }

        return $result;
    }
}
