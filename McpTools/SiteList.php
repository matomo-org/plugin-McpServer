<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\McpToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Sites\SiteSummaryRecord;
use Piwik\Plugins\McpServer\Schemas\Sites\SiteToolSchemas;
use Piwik\Plugins\McpServer\Support\Pagination\SitesPagination;
use Piwik\Plugins\McpServer\Support\Tooling\CursorContextBuilder;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @phpstan-import-type SiteSummaryArray from SiteSummaryRecord
 */
class SiteList extends McpTool
{
    public const TOOL_NAME = 'matomo_site_list';

    public function __construct(
        private SiteSummaryQueryServiceInterface $queryService,
        private PaginatedCollectionResponder $paginationResponder,
    ) {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need to list accessible Matomo sites without a search hint.\n"
            . "Purpose: return paginated site summaries for all sites the user can view.\n"
            . "Next: call " . SiteGet::TOOL_NAME . "(idSite) for full details of one site.";
        $this->annotations = new McpToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
        $this->inputSchema = [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => SitesPagination::LIMIT_MAX,
                    'description' => 'Maximum number of results to return. Uses schema constraints.',
                ],
                'cursor' => [
                    'type' => 'string',
                    'description' => 'Opaque cursor for pagination.',
                ],
                'sort' => [
                    'type' => 'string',
                    'enum' => [
                        SitesPagination::SORT_NAME_ASC,
                        SitesPagination::SORT_NAME_DESC,
                        SitesPagination::SORT_ID_ASC,
                        SitesPagination::SORT_ID_DESC,
                    ],
                    'description' => 'Sort order for results.',
                ],
            ],
            'additionalProperties' => false,
        ];
        $this->outputSchema = SiteToolSchemas::PAGINATED_LIST;
    }

    /**
     * @return array{
     *     sites: list<SiteSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     *     total_rows: int,
     * }
     */
    public function execute(?int $limit = null, ?string $cursor = null, ?string $sort = null): array
    {
        $cursorContext = CursorContextBuilder::forTool(self::TOOL_NAME);
        $response = $this->paginationResponder->paginateRecords(
            $this->queryService->getSiteSummariesForList(),
            static fn(SiteSummaryRecord $site): array => $site->toArray(),
            'sites',
            SitesPagination::createConfig(),
            SitesPagination::SORT_NAME_ASC,
            $limit,
            $cursor,
            $sort,
            $cursorContext,
            static fn(SiteSummaryRecord $site): array => [
                'name' => $site->name,
                'idsite' => $site->idSite,
            ]
        );

        /** @var array{sites: list<SiteSummaryArray>, next_cursor: string|null, has_more: bool, total_rows: int} $response */
        return $response;
    }
}
