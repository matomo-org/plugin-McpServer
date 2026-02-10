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
use Piwik\Plugins\McpServer\ApiWrappers\SegmentEditor\ListApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Segments\ListApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentSummaryRecord;
use Piwik\Plugins\McpServer\Schemas\Segments\SegmentSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Pagination\SegmentsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\SegmentSummaryPaginationResponder;

/**
 * @phpstan-import-type SegmentSummaryArray from SegmentSummaryRecord
 */
class SegmentList
{
    public const TOOL_NAME = 'matomo_segment_list';

    public function __construct(
        private ?ListApiWrapperInterface $apiWrapper = null,
        private ?SegmentSummaryPaginationResponder $paginationResponder = null
    ) {
    }

    /**
     * @return array{
     *     segments: list<SegmentSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: you need reusable saved segments for a specific site.\n"
            . "Purpose: return paginated saved segment definitions available for idSite.\n"
            . "Next: use the chosen segment definition in analytics/report API calls.",
        outputSchema: SegmentSummaryToolOutputSchema::PAGINATED_LIST
    )]
    #[Schema(
        type: 'object',
        properties: [
            'idSite' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Matomo site ID used to scope available saved segments.',
            ],
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => SegmentsPagination::LIMIT_MAX,
                'description' => 'Maximum number of results to return (default 100, max 500).',
            ],
            'cursor' => [
                'type' => 'string',
                'description' => 'Opaque cursor for pagination.',
            ],
            'sort' => [
                'type' => 'string',
                'enum' => [
                    SegmentsPagination::SORT_NAME_ASC,
                    SegmentsPagination::SORT_NAME_DESC,
                    SegmentsPagination::SORT_ID_ASC,
                    SegmentsPagination::SORT_ID_DESC,
                ],
                'description' => 'Sort order for results.',
            ],
        ],
        required: ['idSite'],
        additionalProperties: false
    )]
    public function list(int $idSite, ?int $limit = null, ?string $cursor = null, ?string $sort = null): array
    {
        $cursorContext = hash('sha256', 'segment-list:idSite:' . (string) $idSite);
        return $this->getPaginationResponder()->paginateSegmentSummaryRecords(
            $this->getApiWrapper()->getSegmentsForSite($idSite),
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

    private function getPaginationResponder(): SegmentSummaryPaginationResponder
    {
        return $this->paginationResponder ??= new SegmentSummaryPaginationResponder();
    }
}
