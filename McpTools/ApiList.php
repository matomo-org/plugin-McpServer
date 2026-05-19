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
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryQueryRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\Schemas\Api\ApiListToolInputSchema;
use Piwik\Plugins\McpServer\Schemas\Api\ApiMethodSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\Support\Pagination\ApiMethodsPagination;
use Piwik\Plugins\McpServer\Support\Tooling\CursorContextBuilder;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @phpstan-import-type ApiMethodSummaryArray from ApiMethodSummaryRecord
 */
class ApiList extends McpTool
{
    public const TOOL_NAME = 'matomo_api_list';

    public function __construct(
        private ApiMethodSummaryQueryServiceInterface $queryService,
        private PaginatedCollectionResponder $paginationResponder,
        private SystemSettings $systemSettings,
    ) {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need discoverable Matomo API methods and parameter metadata.\n"
            . "Purpose: return paginated API method summaries aligned with Matomo API docs visibility.\n"
            . "Next: choose a method and map parameters for subsequent raw API tooling.";
        $this->annotations = new McpToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
        $this->inputSchema = ApiListToolInputSchema::SCHEMA;
        $this->outputSchema = ApiMethodSummaryToolOutputSchema::PAGINATED_LIST;
    }

    public function shouldRegister(): bool
    {
        return RawApiAccessMode::allowsToolRegistration($this->systemSettings->getRawApiAccessMode());
    }

    /**
     * @return array{
     *     methods: list<ApiMethodSummaryArray>,
     *     next_cursor: string|null,
     *     has_more: bool,
     *     total_rows: int,
     * }
     */
    public function execute(
        ?int $limit = null,
        ?string $cursor = null,
        ?string $sort = null,
        ?string $module = null,
        ?string $search = null,
        ?string $category = null,
    ): array {
        $query = ApiMethodSummaryQueryRecord::fromInputs(
            $this->systemSettings->getRawApiAccessMode(),
            $module,
            $search,
            $category,
        );

        $cursorContext = CursorContextBuilder::forTool(self::TOOL_NAME, [
            'module' => $query->module,
            'search' => $query->search,
            'category' => $query->operationCategory,
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
