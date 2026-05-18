<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Segments\SegmentSummaryRecord;
use Piwik\Plugins\McpServer\Schemas\Segments\SegmentSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Pagination\SegmentsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\CursorContextBuilder;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @phpstan-import-type SegmentSummaryArray from SegmentSummaryRecord
 */
class SegmentList extends McpTool
{
    public const TOOL_NAME = 'matomo_segment_list';

    public function __construct(
        private SegmentSummaryQueryServiceInterface $queryService,
        private PaginatedCollectionResponder $paginationResponder,
    ) {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need reusable saved segments for a specific site.\n"
            . "Purpose: return paginated saved segment definitions available for idSite.\n"
            . "Next: use the chosen segment definition in analytics/report API calls.";
        $this->annotations = new ToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
        $this->inputSchema = [
            'type' => 'object',
            'properties' => [
                'idSite' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Matomo site ID used to scope available saved segments.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => SegmentsPagination::LIMIT_MAX,
                    'description' => 'Maximum number of results to return. Uses schema constraints.',
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
            'required' => ['idSite'],
            'additionalProperties' => false,
        ];
        $this->outputSchema = SegmentSummaryToolOutputSchema::PAGINATED_LIST;
    }

    /**
     * @return array{
     *     segments: list<SegmentSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     *     total_rows: int,
     * }
     */
    public function execute(int $idSite, ?int $limit = null, ?string $cursor = null, ?string $sort = null): array
    {
        $cursorContext = CursorContextBuilder::forTool(self::TOOL_NAME, ['idSite' => $idSite]);
        $response = $this->paginationResponder->paginateRecords(
            $this->queryService->getSegmentSummariesForSite($idSite),
            static fn(SegmentSummaryRecord $segment): array => $segment->toArray(),
            'segments',
            SegmentsPagination::createConfig(),
            SegmentsPagination::SORT_NAME_ASC,
            $limit,
            $cursor,
            $sort,
            $cursorContext,
            static fn(SegmentSummaryRecord $segment): array => [
                'name' => $segment->name,
                'idsegment' => $segment->idSegment,
            ]
        );

        /** @var array{segments: list<SegmentSummaryArray>, next_cursor: string|null, has_more: bool, total_rows: int} $response */
        return $response;
    }
}
