<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Tooling;

use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryRecord;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;
use Piwik\Plugins\McpServer\Support\Pagination\SitesPagination;

/**
 * @phpstan-import-type SiteSummaryArray from SiteSummaryRecord
 */
final class SiteSummaryPaginationResponder
{
    public function __construct(
        private ?CursorPaginator $paginator = null,
        private ?PaginationConfig $paginationConfig = null
    ) {
    }

    /**
     * @param array<int, SiteSummaryRecord> $records
     * @return array{
     *     sites: list<SiteSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     * }
     */
    public function paginateSiteSummaryRecords(
        array $records,
        ?int $limit = null,
        ?string $cursor = null,
        ?string $sort = null,
        ?string $cursorContext = null
    ): array {
        $sort = $sort ?? SitesPagination::SORT_NAME_ASC;
        /** @var list<SiteSummaryArray> $resultSites */
        $resultSites = array_map(
            static fn(SiteSummaryRecord $site): array => $site->toArray(),
            $records
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

    private function getPaginator(): CursorPaginator
    {
        return $this->paginator ??= new CursorPaginator();
    }

    private function getPaginationConfig(): PaginationConfig
    {
        return $this->paginationConfig ??= SitesPagination::createConfig();
    }
}
