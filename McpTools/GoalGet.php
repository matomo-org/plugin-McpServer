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
use Piwik\Plugins\McpServer\Contracts\Records\Goals\GoalDetailRecord;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Schemas\Goals\GoalDetailToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;

/**
 * @phpstan-import-type GoalDetailArray from GoalDetailRecord
 */
class GoalGet
{
    public const TOOL_NAME = 'matomo_goal_get';

    public function __construct(private GoalDetailQueryServiceInterface $queryService)
    {
    }

    /**
     * @return GoalDetailArray
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: idSite and idGoal are known"
            . " (from the user or " . GoalList::TOOL_NAME . ").\n"
            . "Purpose: fetch authoritative details for exactly one configured goal.\n"
            . "Do not use: if goal id is unknown—use " . GoalList::TOOL_NAME . " first.\n"
            . ToolOutputSecurity::SAFETY_WARNING_TEXT,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
        outputSchema: GoalDetailToolOutputSchema::ITEM
    )]
    #[Schema(
        type: 'object',
        properties: [
            'idSite' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Matomo site ID used to scope goal lookup.',
            ],
            'idGoal' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Goal ID configured for the site.',
            ],
        ],
        required: ['idSite', 'idGoal'],
        additionalProperties: false
    )]
    public function get(int $idSite, int $idGoal): array
    {
        $goal = $this->queryService->getGoalDetailForSite($idSite, $idGoal)->toArray();
        return ['security' => ToolOutputSecurity::buildForTool(self::TOOL_NAME)] + $goal;
    }
}
