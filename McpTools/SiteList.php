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
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\ListApiWrapper;
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\ListApiWrapperInterface;
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\SiteSummaryRecord;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\KeySpec;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;
use Piwik\Plugins\McpServer\Support\Pagination\SortDirection;
use Piwik\Plugins\McpServer\Support\Pagination\SortSpec;

/**
 * @phpstan-import-type SiteSummaryArray from SiteSummaryRecord
 */
class SiteList
{
    public const TOOL_NAME = 'matomo_site_list';

    public const SORT_NAME_ASC = 'name_asc';
    public const SORT_NAME_DESC = 'name_desc';
    public const SORT_ID_ASC = 'id_asc';
    public const SORT_ID_DESC = 'id_desc';

    private const LIMIT_DEFAULT = 100;
    private const LIMIT_MAX = 500;

    public function __construct(private ?ListApiWrapperInterface $apiWrapper = null)
    {
    }

    /**
     * @param self::SORT_*|null $sort
     *
     * @return array{
     *     sites: list<SiteSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: you need to list accessible Matomo sites without a search hint.\n"
            . "Purpose: return paginated site summaries for all sites the user can view.\n"
            . "Next: call " . SiteGet::TOOL_NAME . "(idSite) for full details of one site.",
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
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => self::LIMIT_MAX,
                'description' => 'Maximum number of results to return (default 100, max 500).',
            ],
            'cursor' => [
                'type' => 'string',
                'description' => 'Opaque cursor for pagination.',
            ],
            'sort' => [
                'type' => 'string',
                'enum' => [self::SORT_NAME_ASC, self::SORT_NAME_DESC, self::SORT_ID_ASC, self::SORT_ID_DESC],
                'description' => 'Sort order for results.',
            ],
        ],
        additionalProperties: false
    )]
    public function list(?int $limit = null, ?string $cursor = null, ?string $sort = null): array
    {
        $sort = $sort ?? self::SORT_NAME_ASC;
        /** @var list<SiteSummaryArray> $resultSites */
        $resultSites = array_map(
            static fn(SiteSummaryRecord $site): array => $site->toArray(),
            $this->getApiWrapper()->getSitesWithViewAccess()
        );
        $page = $this->getPaginator()->paginate(
            $resultSites,
            new PageRequest($limit, $sort, $cursor),
            $this->getPaginationConfig()
        );

        return [
            'sites' => $page->items,
            'next_cursor' => $page->nextCursor,
            'has_more' => $page->hasMore,
        ];
    }

    private function getApiWrapper(): ListApiWrapperInterface
    {
        return $this->apiWrapper ??= new ListApiWrapper();
    }

    private function getPaginator(): CursorPaginator
    {
        return new CursorPaginator();
    }

    private function getPaginationConfig(): PaginationConfig
    {
        return new PaginationConfig(
            self::LIMIT_DEFAULT,
            self::LIMIT_MAX,
            self::SORT_NAME_ASC,
            [
                new SortSpec(
                    self::SORT_NAME_ASC,
                    new KeySpec('name', KeySpec::TYPE_STRING, SortDirection::ASC),
                    [new KeySpec('idsite', KeySpec::TYPE_INT, SortDirection::ASC)]
                ),
                new SortSpec(
                    self::SORT_NAME_DESC,
                    new KeySpec('name', KeySpec::TYPE_STRING, SortDirection::DESC),
                    [new KeySpec('idsite', KeySpec::TYPE_INT, SortDirection::DESC)]
                ),
                new SortSpec(
                    self::SORT_ID_ASC,
                    new KeySpec('idsite', KeySpec::TYPE_INT, SortDirection::ASC)
                ),
                new SortSpec(
                    self::SORT_ID_DESC,
                    new KeySpec('idsite', KeySpec::TYPE_INT, SortDirection::DESC)
                ),
            ]
        );
    }
}
