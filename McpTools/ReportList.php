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
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportSummaryRecord;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Pagination\ReportsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\CursorContextBuilder;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @phpstan-import-type ReportSummaryArray from ReportSummaryRecord
 */
class ReportList extends McpTool
{
    public const TOOL_NAME = 'matomo_report_list';

    public function __construct(
        private ReportSummaryQueryServiceInterface $queryService,
        private PaginatedCollectionResponder $paginationResponder,
    ) {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need a compact discovery list of available reports "
            . "for a site, including subtable reports.\n"
            . "Purpose: return paginated report metadata for idSite.\n"
            . "Next: choose reportUniqueId or module/action/parameters and call Matomo reporting APIs.";
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
                    'description' => 'Matomo site ID used to scope available reports.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => ReportsPagination::LIMIT_MAX,
                    'description' => 'Maximum number of results to return. Uses schema constraints.',
                ],
                'cursor' => [
                    'type' => 'string',
                    'description' => 'Opaque cursor for pagination.',
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => [
                        ReportsPagination::SORT_CATEGORY_ASC,
                        ReportsPagination::SORT_CATEGORY_DESC,
                        ReportsPagination::SORT_NAME_ASC,
                        ReportsPagination::SORT_NAME_DESC,
                    ],
                    'description' => 'Sort order for results.',
                ],
            ],
            'required' => ['idSite'],
            'additionalProperties' => false,
        ];
        $this->outputSchema = ReportSummaryToolOutputSchema::PAGINATED_LIST;
    }

    /**
     * @return array{
     *     reports: list<ReportSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     *     total_rows: int,
     * }
     */
    public function execute(int $idSite, ?int $limit = null, ?string $cursor = null, ?string $sort = null): array
    {
        $cursorContext = CursorContextBuilder::forTool(self::TOOL_NAME, ['idSite' => $idSite]);
        $response = $this->paginationResponder->paginateRecords(
            $this->queryService->getReportSummariesForSite($idSite),
            static fn(ReportSummaryRecord $report): array => $report->toArray(),
            'reports',
            ReportsPagination::createConfig(),
            ReportsPagination::SORT_CATEGORY_ASC,
            $limit,
            $cursor,
            $sort,
            $cursorContext,
            static fn(ReportSummaryRecord $report): array => [
                'category' => $report->category,
                'name' => $report->name,
                'uniqueId' => $report->uniqueId,
            ]
        );

        /** @var array{reports: list<ReportSummaryArray>, next_cursor: string|null, has_more: bool, total_rows: int} $response */
        return $response;
    }
}
