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
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\SearchApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Sites\SearchApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryRecord;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;
use Piwik\Plugins\McpServer\Support\Pagination\SitesPagination;

/**
 * @phpstan-import-type SiteSummaryArray from SiteSummaryRecord
 */
class SiteSearch
{
    public const TOOL_NAME = 'matomo_site_search';

    public function __construct(private ?SearchApiWrapperInterface $apiWrapper = null)
    {
    }

    /**
     * @return array{
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
            . "Next: call " . SiteGet::TOOL_NAME . "(idSite) with the chosen idSite.",
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'sites' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'idsite' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'main_url' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                        ],
                        'required' => ['idsite', 'name', 'main_url', 'type'],
                        'additionalProperties' => false,
                    ],
                ],
                'next_cursor' => ['type' => ['string', 'null']],
                'has_more' => ['type' => 'boolean'],
            ],
            'required' => ['sites', 'next_cursor', 'has_more'],
            'additionalProperties' => false,
        ]
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
                'description' => 'Maximum number of results to return (default 100, max 500).',
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

        $sort = $sort ?? SitesPagination::SORT_NAME_ASC;
        $cursorContext = hash('sha256', $search);
        /** @var list<SiteSummaryArray> $resultSites */
        $resultSites = array_map(
            static fn(SiteSummaryRecord $site): array => $site->toArray(),
            $this->getApiWrapper()->searchSitesWithViewAccess($search)
        );

        $page = $this->getPaginator()->paginate(
            $resultSites,
            new PageRequest($limit, $sort, $cursor, $cursorContext),
            $this->getPaginationConfig()
        );

        return [
            'sites' => $page->items,
            'next_cursor' => $page->nextCursor,
            'has_more' => $page->hasMore,
        ];
    }

    private function getApiWrapper(): SearchApiWrapperInterface
    {
        return $this->apiWrapper ??= new SearchApiWrapper();
    }

    private function getPaginator(): CursorPaginator
    {
        return new CursorPaginator();
    }

    private function getPaginationConfig(): PaginationConfig
    {
        return SitesPagination::createConfig();
    }
}
