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
use Piwik\Plugins\McpServer\Contracts\Records\Dimensions\DimensionSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Schemas\Dimensions\DimensionSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Pagination\DimensionsPagination;
use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;
use Piwik\Plugins\McpServer\Support\Tooling\CursorContextBuilder;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @phpstan-import-type DimensionSummaryArray from DimensionSummaryRecord
 */
class DimensionList
{
    public const TOOL_NAME = 'matomo_dimension_list';

    public function __construct(
        private DimensionSummaryQueryServiceInterface $queryService,
        private PaginatedCollectionResponder $paginationResponder
    ) {
    }

    /**
     * @return array{
     *     security: array<string, mixed>,
     *     dimensions: list<DimensionSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: you need idDimension values for processed report retrieval.\n"
            . "Purpose: return paginated active custom dimensions configured for a specific site.\n"
            . "Next: use the chosen iddimension in analytics/report API calls.\n"
            . ToolOutputSecurity::SAFETY_WARNING_TEXT,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
        outputSchema: DimensionSummaryToolOutputSchema::PAGINATED_LIST
    )]
    #[Schema(
        type: 'object',
        properties: [
            'idSite' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Matomo site ID used to scope available dimensions.',
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => DimensionsPagination::LIMIT_MAX,
                'description' => 'Maximum number of results to return. Uses schema constraints.',
            ],
            'cursor' => [
                'type' => 'string',
                'description' => 'Opaque cursor for pagination.',
            ],
            'sort' => [
                'type' => 'string',
                'enum' => [
                    DimensionsPagination::SORT_NAME_ASC,
                    DimensionsPagination::SORT_NAME_DESC,
                    DimensionsPagination::SORT_ID_ASC,
                    DimensionsPagination::SORT_ID_DESC,
                ],
                'description' => 'Sort order for results.',
            ],
        ],
        required: ['idSite'],
        additionalProperties: false
    )]
    public function list(int $idSite, ?int $limit = null, ?string $cursor = null, ?string $sort = null): array
    {
        $cursorContext = CursorContextBuilder::forTool(self::TOOL_NAME, ['idSite' => $idSite]);
        $response = $this->paginationResponder->paginateRecords(
            $this->queryService->getDimensionSummariesForSite($idSite),
            static fn(DimensionSummaryRecord $dimension): array => $dimension->toArray(),
            'dimensions',
            DimensionsPagination::createConfig(),
            DimensionsPagination::SORT_NAME_ASC,
            $limit,
            $cursor,
            $sort,
            $cursorContext,
            static fn(DimensionSummaryRecord $dimension): array => [
                'name' => $dimension->name,
                'iddimension' => $dimension->idDimension,
            ]
        );

        /** @var array{dimensions: list<DimensionSummaryArray>, next_cursor: string|null, has_more: bool} $response */
        return [
            'security' => ToolOutputSecurity::buildForTool(self::TOOL_NAME),
            'dimensions' => $response['dimensions'],
            'next_cursor' => $response['next_cursor'],
            'has_more' => $response['has_more'],
        ];
    }
}
