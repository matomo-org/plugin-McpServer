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
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Schemas\Dimensions\DimensionSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionSummaryQueryService;
use Piwik\Plugins\McpServer\Support\Pagination\DimensionsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\DimensionSummaryPaginationResponder;

/**
 * @phpstan-import-type DimensionSummaryArray from DimensionSummaryRecord
 */
class DimensionList
{
    public const TOOL_NAME = 'matomo_dimension_list';

    public function __construct(
        private ?DimensionSummaryQueryServiceInterface $queryService = null,
        private ?DimensionSummaryPaginationResponder $paginationResponder = null
    ) {
    }

    /**
     * @return array{
     *     dimensions: list<DimensionSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: you need idDimension values for processed report retrieval.\n"
            . "Purpose: return paginated active custom dimensions configured for a specific site.\n"
            . "Next: use the chosen iddimension in analytics/report API calls.",
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
        $cursorContext = hash('sha256', 'dimension-list:idSite:' . (string) $idSite);
        return $this->getPaginationResponder()->paginateDimensionSummaryRecords(
            $this->getQueryService()->getDimensionSummariesForSite($idSite),
            $limit,
            $cursor,
            $sort,
            $cursorContext
        );
    }

    private function getQueryService(): DimensionSummaryQueryServiceInterface
    {
        return $this->queryService ??= new DimensionSummaryQueryService();
    }

    private function getPaginationResponder(): DimensionSummaryPaginationResponder
    {
        return $this->paginationResponder ??= new DimensionSummaryPaginationResponder();
    }
}
