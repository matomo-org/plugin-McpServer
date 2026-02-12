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
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Goals\GoalSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Schemas\Goals\GoalSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Services\Goals\GoalSummaryQueryService;
use Piwik\Plugins\McpServer\Support\Pagination\GoalsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @phpstan-import-type GoalSummaryArray from GoalSummaryRecord
 */
class GoalList
{
    public const TOOL_NAME = 'matomo_goal_list';

    public function __construct(
        private ?GoalSummaryQueryServiceInterface $queryService = null,
        private ?PaginatedCollectionResponder $paginationResponder = null
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
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
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
                'description' => 'Maximum number of results to return. Uses schema constraints.',
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
        $response = $this->getPaginationResponder()->paginateRecords(
            $this->getQueryService()->getGoalSummariesForSite($idSite),
            static fn(GoalSummaryRecord $goal): array => $goal->toArray(),
            'goals',
            GoalsPagination::createConfig(),
            GoalsPagination::SORT_NAME_ASC,
            $limit,
            $cursor,
            $sort,
            $cursorContext
        );

        /** @var array{goals: list<GoalSummaryArray>, next_cursor: string|null, has_more: bool} $response */
        return $response;
    }

    private function getQueryService(): GoalSummaryQueryServiceInterface
    {
        return $this->queryService ??= new GoalSummaryQueryService();
    }

    private function getPaginationResponder(): PaginatedCollectionResponder
    {
        return $this->paginationResponder ??= new PaginatedCollectionResponder();
    }
}
