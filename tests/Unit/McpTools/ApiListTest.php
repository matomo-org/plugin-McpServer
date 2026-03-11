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
use Piwik\Config;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryQueryRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\ApiList;
use Piwik\Plugins\McpServer\Support\Pagination\ApiMethodsPagination;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiListTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalMcpServerConfig = null;

    public function setUp(): void
    {
        parent::setUp();

        $originalConfig = Config::getInstance()->McpServer ?? null;
        $this->originalMcpServerConfig = is_array($originalConfig) ? $originalConfig : null;
    }

    public function tearDown(): void
    {
        Config::getInstance()->McpServer = $this->originalMcpServerConfig;

        parent::tearDown();
    }

    public function testListReturnsReadOnlyMethodsInReadMode(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

        $tool = new ApiList(
            $this->createQueryServiceStub(
                static fn(ApiMethodSummaryQueryRecord $query): array => [
                    new ApiMethodSummaryRecord('UsersManager', 'getUsers', 'UsersManager.getUsers', []),
                ]
            ),
            new PaginatedCollectionResponder(new CursorPaginator()),
        );

        $actual = $tool->list(limit: 10, sort: ApiMethodsPagination::SORT_METHOD_ASC);

        self::assertSame([
            'methods' => [
                [
                    'module' => 'UsersManager',
                    'action' => 'getUsers',
                    'method' => 'UsersManager.getUsers',
                    'parameters' => [],
                ],
            ],
            'next_cursor' => null,
            'has_more' => false,
            'total_rows' => 1,
        ], $actual);
    }

    public function testListReturnsAllMethodsInFullModeAndSupportsFilters(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];
        $capturedQuery = null;

        $tool = new ApiList(
            $this->createQueryServiceStub(
                static function (ApiMethodSummaryQueryRecord $query) use (&$capturedQuery): array {
                    $capturedQuery = $query;

                    return [
                        new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []),
                    ];
                },
            ),
            new PaginatedCollectionResponder(new CursorPaginator()),
        );

        $actual = $tool->list(module: 'usersmanager', search: 'add', limit: 10);

        self::assertSame([
            'methods' => [
                [
                    'module' => 'UsersManager',
                    'action' => 'addUser',
                    'method' => 'UsersManager.addUser',
                    'parameters' => [],
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
    }

    public function testListRejectsInvalidCursor(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];

        $tool = new ApiList(
            $this->createQueryServiceStub(static fn(ApiMethodSummaryQueryRecord $query): array => []),
            new PaginatedCollectionResponder(new CursorPaginator()),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $tool->list(cursor: 'invalid');
    }

    public function testListSupportsPaginationAndSortOrdering(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];

        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
        );

        $firstPage = $tool->list(limit: 2, sort: ApiMethodsPagination::SORT_METHOD_ASC);
        self::assertCount(2, $firstPage['methods']);
        self::assertTrue($firstPage['has_more']);
        self::assertIsString($firstPage['next_cursor']);
        self::assertSame(5, $firstPage['total_rows']);

        $secondPage = $tool->list(
            limit: 2,
            cursor: $firstPage['next_cursor'],
            sort: ApiMethodsPagination::SORT_METHOD_ASC,
        );
        self::assertCount(2, $secondPage['methods']);
        self::assertTrue($secondPage['has_more']);
        self::assertIsString($secondPage['next_cursor']);
        self::assertSame(5, $secondPage['total_rows']);

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
        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
        );

        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];
        $firstPage = $tool->list(limit: 1, sort: ApiMethodsPagination::SORT_METHOD_ASC);
        $cursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($cursor);

        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');
        $tool->list(limit: 1, cursor: $cursor, sort: ApiMethodsPagination::SORT_METHOD_ASC);
    }

    public function testListRejectsCursorWhenSearchChanges(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];

        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
        );

        $firstPage = $tool->list(limit: 1, sort: ApiMethodsPagination::SORT_METHOD_ASC, search: 'get');
        $cursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($cursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');
        $tool->list(limit: 1, cursor: $cursor, sort: ApiMethodsPagination::SORT_METHOD_ASC, search: 'add');
    }

    public function testListRejectsCursorWhenModuleChanges(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];

        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
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
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];

        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
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
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];

        $tool = new ApiList(
            $this->createExpandedQueryServiceStub(),
            new PaginatedCollectionResponder(new CursorPaginator()),
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
                    new ApiMethodSummaryRecord('API', 'getMatomoVersion', 'API.getMatomoVersion', []),
                    new ApiMethodSummaryRecord('SitesManager', 'deleteSite', 'SitesManager.deleteSite', []),
                    new ApiMethodSummaryRecord('SitesManager', 'isSiteNameUnique', 'SitesManager.isSiteNameUnique', []),
                    new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []),
                    new ApiMethodSummaryRecord('UsersManager', 'getUsers', 'UsersManager.getUsers', []),
                ];

                return array_values(array_filter(
                    $records,
                    static function (ApiMethodSummaryRecord $record) use ($query): bool {
                        if (
                            $query->accessMode === 'read'
                            && !str_starts_with(strtolower($record->action), 'get')
                            && !str_starts_with(strtolower($record->action), 'is')
                        ) {
                            return false;
                        }

                        if ($query->module !== '' && strtolower($record->module) !== $query->module) {
                            return false;
                        }

                        if ($query->search === '') {
                            return true;
                        }

                        return str_contains(strtolower($record->method), $query->search)
                            || str_contains(strtolower($record->module), $query->search)
                            || str_contains(strtolower($record->action), $query->search);
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
}
