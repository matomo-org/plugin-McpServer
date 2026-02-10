<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Capability\Attribute\McpTool;
use Matomo\Dependencies\McpServer\Mcp\Capability\Attribute\Schema;
use Piwik\Plugins\McpServer\ApiWrappers\Goals\ListApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Goals\ListApiWrapperInterface;
use Piwik\Plugins\McpServer\Schemas\Goals\GoalSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Pagination\GoalsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\GoalSummaryPaginationResponder;

/**
 * @phpstan-import-type GoalSummaryArray from GoalSummaryRecord
 */
class GoalList
{
    public const TOOL_NAME = 'matomo_goal_list';

    public function __construct(
        private ?ListApiWrapperInterface $apiWrapper = null,
        private ?GoalSummaryPaginationResponder $paginationResponder = null
    ) {
    }

    /**
     * @return array{
     *     goals: list<GoalSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: you need reusable configured goals for a specific site.\n"
            . "Purpose: return paginated goal definitions available for idSite.\n"
            . "Next: use the chosen idgoal in goal-specific analytics/report API calls.",
        outputSchema: GoalSummaryToolOutputSchema::PAGINATED_LIST
    )]
    #[Schema(
        type: 'object',
        properties: [
            'idSite' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Matomo site ID used to scope available goals.',
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => GoalsPagination::LIMIT_MAX,
                'description' => 'Maximum number of results to return (default 100, max 500).',
            ],
            'cursor' => [
                'type' => 'string',
                'description' => 'Opaque cursor for pagination.',
            ],
            'sort' => [
                'type' => 'string',
                'enum' => [
                    GoalsPagination::SORT_NAME_ASC,
                    GoalsPagination::SORT_NAME_DESC,
                    GoalsPagination::SORT_ID_ASC,
                    GoalsPagination::SORT_ID_DESC,
                ],
                'description' => 'Sort order for results.',
            ],
        ],
        required: ['idSite'],
        additionalProperties: false
    )]
    public function list(int $idSite, ?int $limit = null, ?string $cursor = null, ?string $sort = null): array
    {
        $cursorContext = hash('sha256', 'goal-list:idSite:' . (string) $idSite);
        return $this->getPaginationResponder()->paginateGoalSummaryRecords(
            $this->getApiWrapper()->getGoalsForSite($idSite),
            $limit,
            $cursor,
            $sort,
            $cursorContext
        );
    }

    private function getApiWrapper(): ListApiWrapperInterface
    {
        return $this->apiWrapper ??= new ListApiWrapper();
    }

    private function getPaginationResponder(): GoalSummaryPaginationResponder
    {
        return $this->paginationResponder ??= new GoalSummaryPaginationResponder();
    }
}
