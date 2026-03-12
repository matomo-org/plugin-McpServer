<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryQueryRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\Support\Pagination\ApiMethodsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\CursorContextBuilder;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @phpstan-import-type ApiMethodSummaryArray from ApiMethodSummaryRecord
 */
class ApiList
{
    public const TOOL_NAME = 'matomo_api_list';

    public function __construct(
        private ApiMethodSummaryQueryServiceInterface $queryService,
        private PaginatedCollectionResponder $paginationResponder,
        private SystemSettings $systemSettings,
    ) {
    }

    /**
     * @return array{
     *     methods: list<ApiMethodSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     *     total_rows: int,
     * }
     */
    public function list(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $sort = null,
        ?string $module = null,
        ?string $search = null,
    ): array {
        $query = ApiMethodSummaryQueryRecord::fromInputs(
            $this->systemSettings->getRawApiAccessMode(),
            $module,
            $search,
        );

        $cursorContext = CursorContextBuilder::forTool(self::TOOL_NAME, [
            'module' => $query->module,
            'search' => $query->search,
            'mode' => $query->accessMode,
        ]);

        $response = $this->paginationResponder->paginateRecords(
            $this->queryService->getApiMethodSummaries($query),
            static fn(ApiMethodSummaryRecord $record): array => $record->toArray(),
            'methods',
            ApiMethodsPagination::createConfig(),
            ApiMethodsPagination::SORT_MODULE_ASC,
            $limit,
            $cursor,
            $sort,
            $cursorContext,
            static fn(ApiMethodSummaryRecord $record): array => [
                'module' => $record->module,
                'method' => $record->method,
            ]
        );

        /** @var array{methods: list<ApiMethodSummaryArray>, next_cursor: string|null, has_more: bool, total_rows: int} $response */
        return $response;
    }
}
