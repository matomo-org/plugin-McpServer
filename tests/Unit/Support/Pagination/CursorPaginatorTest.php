<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Pagination;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Pagination\KeySpec;
use Piwik\Plugins\McpServer\Support\Pagination\PageRequest;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;
use Piwik\Plugins\McpServer\Support\Pagination\SortDirection;
use Piwik\Plugins\McpServer\Support\Pagination\SortSpec;

/**
 * @group McpServer
 * @group Plugins
 */
class CursorPaginatorTest extends TestCase
{
    public function testPaginatesByNameAscWithTieBreaker(): void
    {
        $paginator = new CursorPaginator();
        $config = $this->buildNameConfig();
        $items = [
            ['idsite' => 3, 'name' => 'Beta'],
            ['idsite' => 1, 'name' => 'Alpha'],
            ['idsite' => 2, 'name' => 'Beta'],
        ];

        $firstPage = $paginator->paginate($items, new PageRequest(limit: 2, sortToken: 'name_asc'), $config);

        self::assertSame([
            ['idsite' => 1, 'name' => 'Alpha'],
            ['idsite' => 2, 'name' => 'Beta'],
        ], $firstPage->items);
        self::assertTrue($firstPage->hasMore);
        self::assertIsString($firstPage->nextCursor);

        $secondPage = $paginator->paginate(
            $items,
            new PageRequest(limit: 2, sortToken: 'name_asc', cursor: $firstPage->nextCursor),
            $config
        );

        self::assertSame([
            ['idsite' => 3, 'name' => 'Beta'],
        ], $secondPage->items);
        self::assertFalse($secondPage->hasMore);
        self::assertNull($secondPage->nextCursor);
    }

    public function testSupportsPrimaryOnlySortConfig(): void
    {
        $paginator = new CursorPaginator();
        $config = new PaginationConfig(
            defaultLimit: 2,
            maxLimit: 5,
            defaultSortToken: 'login_asc',
            allowedSorts: [
                new SortSpec(
                    'login_asc',
                    new KeySpec('login', KeySpec::TYPE_STRING, SortDirection::ASC)
                ),
            ]
        );
        $items = [
            ['login' => 'carol'],
            ['login' => 'alice'],
            ['login' => 'bob'],
        ];

        $page = $paginator->paginate($items, new PageRequest(limit: 2), $config);

        self::assertSame([
            ['login' => 'alice'],
            ['login' => 'bob'],
        ], $page->items);
        self::assertTrue($page->hasMore);
        self::assertIsString($page->nextCursor);
    }

    public function testUsesBinaryCaseAndAccentSensitiveStringOrdering(): void
    {
        $paginator = new CursorPaginator();
        $config = new PaginationConfig(
            defaultLimit: 10,
            maxLimit: 10,
            defaultSortToken: 'name_asc',
            allowedSorts: [
                new SortSpec(
                    'name_asc',
                    new KeySpec('name', KeySpec::TYPE_STRING, SortDirection::ASC)
                ),
            ]
        );
        $items = [
            ['name' => 'alpha'],
            ['name' => 'Zulu'],
            ['name' => 'Álpha'],
        ];

        $page = $paginator->paginate($items, new PageRequest(), $config);

        self::assertSame([
            ['name' => 'Zulu'],
            ['name' => 'alpha'],
            ['name' => 'Álpha'],
        ], $page->items);
    }

    public function testRejectsInvalidSortToken(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Parameter 'sort' missing or invalid.");

        (new CursorPaginator())->paginate(
            [['idsite' => 1, 'name' => 'A']],
            new PageRequest(limit: 1, sortToken: 'unknown'),
            $this->buildNameConfig()
        );
    }

    public function testRejectsInvalidCursor(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        (new CursorPaginator())->paginate(
            [['idsite' => 1, 'name' => 'A']],
            new PageRequest(limit: 1, sortToken: 'name_asc', cursor: 'invalid'),
            $this->buildNameConfig()
        );
    }

    public function testRejectsCursorSortMismatch(): void
    {
        $paginator = new CursorPaginator();
        $config = $this->buildNameConfig();
        $items = [
            ['idsite' => 1, 'name' => 'Alpha'],
            ['idsite' => 2, 'name' => 'Beta'],
        ];

        $page = $paginator->paginate($items, new PageRequest(limit: 1, sortToken: 'name_asc'), $config);
        self::assertIsString($page->nextCursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $paginator->paginate(
            $items,
            new PageRequest(limit: 1, sortToken: 'name_desc', cursor: $page->nextCursor),
            $config
        );
    }

    public function testAcceptsCursorWhenContextMatches(): void
    {
        $paginator = new CursorPaginator();
        $config = $this->buildNameConfig();
        $items = [
            ['idsite' => 1, 'name' => 'Alpha'],
            ['idsite' => 2, 'name' => 'Beta'],
            ['idsite' => 3, 'name' => 'Gamma'],
        ];

        $firstPage = $paginator->paginate(
            $items,
            new PageRequest(limit: 2, sortToken: 'name_asc', cursorContext: 'search:a'),
            $config
        );
        self::assertIsString($firstPage->nextCursor);

        $secondPage = $paginator->paginate(
            $items,
            new PageRequest(
                limit: 2,
                sortToken: 'name_asc',
                cursor: $firstPage->nextCursor,
                cursorContext: 'search:a'
            ),
            $config
        );

        self::assertSame([
            ['idsite' => 3, 'name' => 'Gamma'],
        ], $secondPage->items);
    }

    public function testRejectsCursorWhenContextMismatches(): void
    {
        $paginator = new CursorPaginator();
        $config = $this->buildNameConfig();
        $items = [
            ['idsite' => 1, 'name' => 'Alpha'],
            ['idsite' => 2, 'name' => 'Beta'],
        ];

        $firstPage = $paginator->paginate(
            $items,
            new PageRequest(limit: 1, sortToken: 'name_asc', cursorContext: 'search:a'),
            $config
        );
        self::assertIsString($firstPage->nextCursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $paginator->paginate(
            $items,
            new PageRequest(
                limit: 1,
                sortToken: 'name_asc',
                cursor: $firstPage->nextCursor,
                cursorContext: 'search:b'
            ),
            $config
        );
    }

    public function testRejectsLegacyCursorWhenContextIsRequired(): void
    {
        $paginator = new CursorPaginator();
        $config = $this->buildNameConfig();
        $items = [
            ['idsite' => 1, 'name' => 'Alpha'],
            ['idsite' => 2, 'name' => 'Beta'],
        ];

        $legacyPage = $paginator->paginate(
            $items,
            new PageRequest(limit: 1, sortToken: 'name_asc'),
            $config
        );
        self::assertIsString($legacyPage->nextCursor);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $paginator->paginate(
            $items,
            new PageRequest(
                limit: 1,
                sortToken: 'name_asc',
                cursor: $legacyPage->nextCursor,
                cursorContext: 'search:a'
            ),
            $config
        );
    }

    public function testRejectsMissingRequiredFieldInRow(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Pagination data is incomplete (missing 'name').");

        (new CursorPaginator())->paginate(
            [['idsite' => 1], ['idsite' => 2, 'name' => 'B']],
            new PageRequest(limit: 1, sortToken: 'name_asc'),
            $this->buildNameConfig()
        );
    }

    public function testRejectsInvalidLimit(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Parameter 'limit' missing or invalid.");

        (new CursorPaginator())->paginate(
            [['idsite' => 1, 'name' => 'A']],
            new PageRequest(limit: 0, sortToken: 'name_asc'),
            $this->buildNameConfig()
        );
    }

    private function buildNameConfig(): PaginationConfig
    {
        return new PaginationConfig(
            defaultLimit: 2,
            maxLimit: 5,
            defaultSortToken: 'name_asc',
            allowedSorts: [
                new SortSpec(
                    'name_asc',
                    new KeySpec('name', KeySpec::TYPE_STRING, SortDirection::ASC),
                    [new KeySpec('idsite', KeySpec::TYPE_INT, SortDirection::ASC)]
                ),
                new SortSpec(
                    'name_desc',
                    new KeySpec('name', KeySpec::TYPE_STRING, SortDirection::DESC),
                    [new KeySpec('idsite', KeySpec::TYPE_INT, SortDirection::DESC)]
                ),
            ]
        );
    }
}
