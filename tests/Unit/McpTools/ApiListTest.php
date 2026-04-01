<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryQueryRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\ApiList;
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;
use Piwik\Plugins\McpServer\Support\Pagination\ApiMethodsPagination;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiListTest extends TestCase
{
    public function testListReturnsReadOnlyMethodsInReadMode(): void
    {
        $tool = new ApiList(
            $this->createQueryServiceStub(
                static fn(ApiMethodSummaryQueryRecord $query): array => [
                    new ApiMethodSummaryRecord(
                        'UsersManager',
                        'getUsers',
                        'UsersManager.getUsers',
                        [],
                        ApiMethodOperationClassifier::CATEGORY_READ,
                        ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                        'action-prefix:get',
                    ),
                ]
            ),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('read'),
        );

        $actual = $tool->list(limit: 10, sort: ApiMethodsPagination::SORT_METHOD_ASC);

        self::assertSame([
            'methods' => [
                [
                    'module' => 'UsersManager',
                    'action' => 'getUsers',
                    'method' => 'UsersManager.getUsers',
                    'parameters' => [],
                    'operationCategory' => 'read',
                ],
            ],
            'next_cursor' => null,
            'has_more' => false,
            'total_rows' => 1,
        ], $actual);
    }

    public function testListReturnsAllMethodsInFullModeAndSupportsFilters(): void
    {
        $capturedQuery = null;

        $tool = new ApiList(
            $this->createQueryServiceStub(
                static function (ApiMethodSummaryQueryRecord $query) use (&$capturedQuery): array {
                        $capturedQuery = $query;

                        return [
                            new ApiMethodSummaryRecord(
                                'UsersManager',
                                'addUser',
                                'UsersManager.addUser',
                                [],
                                ApiMethodOperationClassifier::CATEGORY_CREATE,
                                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                                'action-prefix:add',
                            ),
                        ];
                },
            ),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('full'),
        );

        $actual = $tool->list(module: 'usersmanager', search: 'add', category: 'create', limit: 10);

        self::assertSame([
            'methods' => [
                [
                    'module' => 'UsersManager',
                    'action' => 'addUser',
                    'method' => 'UsersManager.addUser',
                    'parameters' => [],
                    'operationCategory' => 'create',
                ],
            ],
            'next_cursor' => null,
            'has_more' => false,
            'total_rows' => 1,
        ], $actual);
        self::assertInstanceOf(ApiMethodSummaryQueryRecord::class, $capturedQuery);
        self::assertSame('full', $capturedQuery->accessMode);
        self::assertSame('usersmanager', $capturedQuery->module);
        self::assertSame('add', $capturedQuery->search);
        self::assertSame('create', $capturedQuery->operationCategory);
    }

    public function testListSupportsUncategorizedCategoryFilter(): void
    {
        $capturedQuery = null;

        $tool = new ApiList(
            $this->createQueryServiceStub(
                static function (ApiMethodSummaryQueryRecord $query) use (&$capturedQuery): array {
                    $capturedQuery = $query;

                    return [
                        new ApiMethodSummaryRecord(
                            'ScheduledReports',
                            'sendReport',
                            'ScheduledReports.sendReport',
                            [],
                            null,
                            ApiMethodOperationClassifier::CONFIDENCE_LOW,
                            'unsupported-action-prefix:send',
                        ),
                    ];
                },
            ),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('full'),
        );

        $actual = $tool->list(category: 'uncategorized', limit: 10);

        self::assertSame([
            'methods' => [
                [
                    'module' => 'ScheduledReports',
                    'action' => 'sendReport',
                    'method' => 'ScheduledReports.sendReport',
                    'parameters' => [],
                    'operationCategory' => null,
                ],
            ],
            'next_cursor' => null,
            'has_more' => false,
            'total_rows' => 1,
        ], $actual);
        self::assertInstanceOf(ApiMethodSummaryQueryRecord::class, $capturedQuery);
        self::assertSame('uncategorized', $capturedQuery->operationCategory);
    }

    public function testListRejectsInvalidCursor(): void
    {
        $tool = new ApiList(
            $this->createQueryServiceStub(static fn(ApiMethodSummaryQueryRecord $query): array => []),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('full'),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(cursor: 'invalid');
    }

    public function testListSupportsPaginationAndSortOrdering(): void
    {
        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('full'),
        );

        $firstPage = $tool->list(limit: 2, sort: ApiMethodsPagination::SORT_METHOD_ASC);
        self::assertCount(2, $firstPage['methods']);
        self::assertTrue($firstPage['has_more']);
        self::assertIsString($firstPage['next_cursor']);
        self::assertSame(6, $firstPage['total_rows']);

        $secondPage = $tool->list(
            limit: 2,
            cursor: $firstPage['next_cursor'],
            sort: ApiMethodsPagination::SORT_METHOD_ASC,
        );
        self::assertCount(2, $secondPage['methods']);
        self::assertTrue($secondPage['has_more']);
        self::assertIsString($secondPage['next_cursor']);
        self::assertSame(6, $secondPage['total_rows']);

        $firstPageMethods = array_map(
            static fn(array $row): string => $row['method'],
            $firstPage['methods'],
        );
        $secondPageMethods = array_map(
            static fn(array $row): string => $row['method'],
            $secondPage['methods'],
        );
        self::assertSame([], array_values(array_intersect($firstPageMethods, $secondPageMethods)));

        $descPage = $tool->list(limit: 5, sort: ApiMethodsPagination::SORT_METHOD_DESC);
        $descMethods = array_map(
            static fn(array $row): string => $row['method'],
            $descPage['methods'],
        );
        $expectedDesc = $descMethods;
        rsort($expectedDesc);
        self::assertSame($expectedDesc, $descMethods);
    }

    public function testListRejectsCursorWhenModeChanges(): void
    {
        $rawApiAccessMode = 'read';
        $settings = $this->createMutableSystemSettingsStub($rawApiAccessMode);
        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $settings,
        );
        $firstPage = $tool->list(limit: 1, sort: ApiMethodsPagination::SORT_METHOD_ASC);
        $cursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $rawApiAccessMode = 'full';
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');
        $tool->list(limit: 1, cursor: $cursor, sort: ApiMethodsPagination::SORT_METHOD_ASC);
    }

    public function testListRejectsCursorWhenSearchChanges(): void
    {
        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('full'),
        );

        $firstPage = $tool->list(limit: 1, sort: ApiMethodsPagination::SORT_METHOD_ASC, search: 'get');
        $cursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');
        $tool->list(limit: 1, cursor: $cursor, sort: ApiMethodsPagination::SORT_METHOD_ASC, search: 'add');
    }

    public function testListRejectsCursorWhenCategoryChanges(): void
    {
        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('full'),
        );

        $firstPage = $tool->list(limit: 1, sort: ApiMethodsPagination::SORT_METHOD_ASC, category: 'read');
        $cursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');
        $tool->list(limit: 1, cursor: $cursor, sort: ApiMethodsPagination::SORT_METHOD_ASC, category: 'create');
    }

    public function testListRejectsCursorWhenModuleChanges(): void
    {
        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('full'),
        );

        $firstPage = $tool->list(limit: 1, sort: ApiMethodsPagination::SORT_METHOD_ASC, module: 'UsersManager');
        $cursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');
        $tool->list(limit: 1, cursor: $cursor, sort: ApiMethodsPagination::SORT_METHOD_ASC, module: 'SitesManager');
    }

    public function testListAcceptsCursorWhenEquivalentModuleNormalizationIsUsed(): void
    {
        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('full'),
        );

        $firstPage = $tool->list(limit: 1, sort: ApiMethodsPagination::SORT_METHOD_ASC, module: ' UsersManager ');
        $cursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $secondPage = $tool->list(
            limit: 1,
            cursor: $cursor,
            sort: ApiMethodsPagination::SORT_METHOD_ASC,
            module: 'usersmanager',
        );

        self::assertCount(1, $secondPage['methods']);
        self::assertFalse($secondPage['has_more']);
        self::assertNull($secondPage['next_cursor']);
        self::assertSame(2, $secondPage['total_rows']);
    }

    public function testListAcceptsCursorWhenEquivalentSearchNormalizationIsUsed(): void
    {
        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
            $this->createSystemSettingsStub('full'),
        );

        $firstPage = $tool->list(limit: 1, sort: ApiMethodsPagination::SORT_METHOD_ASC, search: '  GET ');
        $cursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $secondPage = $tool->list(
            limit: 1,
            cursor: $cursor,
            sort: ApiMethodsPagination::SORT_METHOD_ASC,
            search: 'get',
        );

        self::assertCount(1, $secondPage['methods']);
        self::assertFalse($secondPage['has_more']);
        self::assertNull($secondPage['next_cursor']);
        self::assertSame(2, $secondPage['total_rows']);
    }

    private function createQueryServiceStub(callable $callback): ApiMethodSummaryQueryServiceInterface
    {
        return new class ($callback) implements ApiMethodSummaryQueryServiceInterface {
            /** @var callable(ApiMethodSummaryQueryRecord): array<int, ApiMethodSummaryRecord> */
            private $callback;

            /** @param callable(ApiMethodSummaryQueryRecord): array<int, ApiMethodSummaryRecord> $callback */
            public function __construct(callable $callback)
            {
                $this->callback = $callback;
            }

            public function getApiMethodSummaries(ApiMethodSummaryQueryRecord $query): array
            {
                return ($this->callback)($query);
            }

            public function getApiMethodSummaryBySelector(
                string $accessMode,
                ?string $method = null,
                ?string $module = null,
                ?string $action = null,
            ): ApiMethodSummaryRecord {
                throw new \BadMethodCallException('Not used in ApiList tests.');
            }
        };
    }

    private function createExpandedQueryServiceStub(): ApiMethodSummaryQueryServiceInterface
    {
        return new class () implements ApiMethodSummaryQueryServiceInterface {
            public function getApiMethodSummaries(ApiMethodSummaryQueryRecord $query): array
            {
                $records = [
                    new ApiMethodSummaryRecord(
                        'API',
                        'getMatomoVersion',
                        'API.getMatomoVersion',
                        [],
                        ApiMethodOperationClassifier::CATEGORY_READ,
                        ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                        'action-prefix:get',
                    ),
                    new ApiMethodSummaryRecord(
                        'SitesManager',
                        'deleteSite',
                        'SitesManager.deleteSite',
                        [],
                        ApiMethodOperationClassifier::CATEGORY_DELETE,
                        ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                        'action-prefix:delete',
                    ),
                    new ApiMethodSummaryRecord(
                        'SitesManager',
                        'isSiteNameUnique',
                        'SitesManager.isSiteNameUnique',
                        [],
                        ApiMethodOperationClassifier::CATEGORY_READ,
                        ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                        'action-prefix:is',
                    ),
                    new ApiMethodSummaryRecord(
                        'UsersManager',
                        'addUser',
                        'UsersManager.addUser',
                        [],
                        ApiMethodOperationClassifier::CATEGORY_CREATE,
                        ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                        'action-prefix:add',
                    ),
                    new ApiMethodSummaryRecord(
                        'UsersManager',
                        'getUsers',
                        'UsersManager.getUsers',
                        [],
                        ApiMethodOperationClassifier::CATEGORY_READ,
                        ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                        'action-prefix:get',
                    ),
                    new ApiMethodSummaryRecord(
                        'ScheduledReports',
                        'sendReport',
                        'ScheduledReports.sendReport',
                        [],
                        null,
                        ApiMethodOperationClassifier::CONFIDENCE_LOW,
                        'unsupported-action-prefix:send',
                    ),
                ];

                return array_values(array_filter(
                    $records,
                    static function (ApiMethodSummaryRecord $record) use ($query): bool {
                        if ($query->accessMode === 'read' && $record->operationCategory !== 'read') {
                            return false;
                        }

                        if (
                            $query->accessMode === 'create'
                            && !in_array($record->operationCategory, ['read', 'create'], true)
                        ) {
                            return false;
                        }

                        if ($query->module !== '' && strtolower($record->module) !== $query->module) {
                            return false;
                        }

                        if ($query->search === '') {
                            if ($query->operationCategory === '') {
                                return true;
                            }

                            return $record->operationCategory === $query->operationCategory;
                        }

                        $matchesSearch = str_contains(strtolower($record->method), $query->search)
                            || str_contains(strtolower($record->module), $query->search)
                            || str_contains(strtolower($record->action), $query->search);
                        if (!$matchesSearch) {
                            return false;
                        }

                        if ($query->operationCategory === '') {
                            return true;
                        }

                        if ($query->operationCategory === ApiMethodOperationClassifier::CATEGORY_UNCATEGORIZED) {
                            return $record->operationCategory === null;
                        }

                        return $record->operationCategory === $query->operationCategory;
                    },
                ));
            }

            public function getApiMethodSummaryBySelector(
                string $accessMode,
                ?string $method = null,
                ?string $module = null,
                ?string $action = null,
            ): ApiMethodSummaryRecord {
                throw new \BadMethodCallException('Not used in ApiList tests.');
            }
        };
    }

    private function createSystemSettingsStub(string $rawApiAccessMode): SystemSettings
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('getRawApiAccessMode')
            ->willReturn($rawApiAccessMode);

        return $settings;
    }

    private function createMutableSystemSettingsStub(string &$rawApiAccessMode): SystemSettings
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('getRawApiAccessMode')
            ->willReturnCallback(static function () use (&$rawApiAccessMode): string {
                return $rawApiAccessMode;
            });

        return $settings;
    }
}
