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
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Pagination\ReportsPagination;
use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;
use Piwik\Plugins\McpServer\Support\Tooling\CursorContextBuilder;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @phpstan-import-type ReportSummaryArray from ReportSummaryRecord
 */
class ReportList
{
    public const TOOL_NAME = 'matomo_report_list';

    public function __construct(
        private ReportSummaryQueryServiceInterface $queryService,
        private PaginatedCollectionResponder $paginationResponder
    ) {
    }

    /**
     * @return array{
     *     security: array<string, mixed>,
     *     reports: list<ReportSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: you need a compact discovery list of available reports for a site.\n"
            . "Purpose: return paginated report metadata for idSite.\n"
            . "Next: choose module/action/parameters and call Matomo reporting APIs.\n"
            . ToolOutputSecurity::SAFETY_WARNING_TEXT,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
        outputSchema: ReportSummaryToolOutputSchema::PAGINATED_LIST
    )]
    #[Schema(
        type: 'object',
        properties: [
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
        required: ['idSite'],
        additionalProperties: false
    )]
    public function list(int $idSite, ?int $limit = null, ?string $cursor = null, ?string $sort = null): array
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

        /** @var array{reports: list<ReportSummaryArray>, next_cursor: string|null, has_more: bool} $response */
        return [
            'security' => ToolOutputSecurity::buildForTool(self::TOOL_NAME),
            'reports' => $response['reports'],
            'next_cursor' => $response['next_cursor'],
            'has_more' => $response['has_more'],
        ];
    }
}
