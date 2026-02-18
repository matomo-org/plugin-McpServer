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
use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\Records\Sites\SiteSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Schemas\Sites\SiteSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Pagination\SitesPagination;
use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;
use Piwik\Plugins\McpServer\Support\Tooling\CursorContextBuilder;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @phpstan-import-type SiteSummaryArray from SiteSummaryRecord
 */
class SiteSearch
{
    public const TOOL_NAME = 'matomo_site_search';

    public function __construct(
        private SiteSummaryQueryServiceInterface $queryService,
        private PaginatedCollectionResponder $paginationResponder
    ) {
    }

    /**
     * @return array{
     *     security: array<string, mixed>,
     *     sites: list<SiteSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: idSite is unknown; you have a URL/domain/name hint.\n"
            . "Purpose: find matching Matomo sites and return candidate idSite values.\n"
            . "Notes: may return multiple matches; results are ordered by sort (default name_asc).\n"
            . "Next: call " . SiteGet::TOOL_NAME . "(idSite) with the chosen idSite.\n"
            . ToolOutputSecurity::SAFETY_WARNING_TEXT,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
        outputSchema: SiteSummaryToolOutputSchema::PAGINATED_LIST
    )]
    #[Schema(
        type: 'object',
        properties: [
            'search' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Required search term (site name or URL).',
            ],
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
        required: ['search'],
        additionalProperties: false
    )]
    public function search(
        string $search,
        ?int $limit = null,
        ?string $cursor = null,
        ?string $sort = null
    ): array {
        $search = trim($search);
        if ($search === '') {
            throw new ToolCallException("Parameter 'search' missing or invalid.");
        }

        $cursorContext = CursorContextBuilder::forTool(self::TOOL_NAME, ['search' => $search]);
        $response = $this->paginationResponder->paginateRecords(
            $this->queryService->getSiteSummariesForSearch($search),
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

        /** @var array{sites: list<SiteSummaryArray>, next_cursor: string|null, has_more: bool} $response */
        return [
            'security' => ToolOutputSecurity::buildForTool(self::TOOL_NAME),
            'sites' => $response['sites'],
            'next_cursor' => $response['next_cursor'],
            'has_more' => $response['has_more'],
        ];
    }
}
