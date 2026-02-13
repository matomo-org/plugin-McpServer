<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Tooling;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Pagination\KeySpec;
use Piwik\Plugins\McpServer\Support\Pagination\PaginationConfig;
use Piwik\Plugins\McpServer\Support\Pagination\SortDirection;
use Piwik\Plugins\McpServer\Support\Pagination\SortSpec;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;

/**
 * @group McpServer
 * @group Plugins
 */
class PaginatedCollectionResponderTest extends TestCase
{
    public function testReturnsCollectionUnderProvidedKeyWithPaginationMetadata(): void
    {
        $responder = new PaginatedCollectionResponder();
        $records = [
            new class ('Beta', 2) {
                public function __construct(
                    public string $name,
                    public int $id
                ) {
                }
            },
            new class ('Alpha', 1) {
                public function __construct(
                    public string $name,
                    public int $id
                ) {
                }
            },
            new class ('Gamma', 3) {
                public function __construct(
                    public string $name,
                    public int $id
                ) {
                }
            },
        ];

        /** @var array{items: list<array{name: string, id: int}>, next_cursor: string|null, has_more: bool} $result */
        $result = $responder->paginateRecords(
            $records,
            static fn(object $record): array => ['name' => $record->name, 'id' => $record->id],
            'items',
            $this->buildConfig(),
            'name_asc',
            limit: 2
        );

        self::assertSame([
            'items' => [
                ['name' => 'Alpha', 'id' => 1],
                ['name' => 'Beta', 'id' => 2],
            ],
            'next_cursor' => $result['next_cursor'],
            'has_more' => true,
        ], $result);
        self::assertIsString($result['next_cursor']);
    }

    public function testUsesExplicitSortWhenProvided(): void
    {
        $responder = new PaginatedCollectionResponder();
        /** @var list<array{name: string, id: int}> $records */
        $records = [
            ['name' => 'Alpha', 'id' => 1],
            ['name' => 'Beta', 'id' => 2],
        ];

        /** @var array{items: list<array{name: string, id: int}>, next_cursor: string|null, has_more: bool} $result */
        $result = $responder->paginateRecords(
            $records,
            /**
             * @param array{name: string, id: int} $record
             * @return array{name: string, id: int}
             */
            static fn(array $record): array => $record,
            'items',
            $this->buildConfig(),
            'name_asc',
            sort: 'name_desc'
        );

        self::assertSame('Beta', $result['items'][0]['name']);
        self::assertSame('Alpha', $result['items'][1]['name']);
    }

    public function testUsesDefaultSortWhenSortIsNull(): void
    {
        $responder = new PaginatedCollectionResponder();
        /** @var list<array{name: string, id: int}> $records */
        $records = [
            ['name' => 'Alpha', 'id' => 1],
            ['name' => 'Beta', 'id' => 2],
        ];

        /** @var array{items: list<array{name: string, id: int}>, next_cursor: string|null, has_more: bool} $result */
        $result = $responder->paginateRecords(
            $records,
            /**
             * @param array{name: string, id: int} $record
             * @return array{name: string, id: int}
             */
            static fn(array $record): array => $record,
            'items',
            $this->buildConfig(),
            'name_desc'
        );

        self::assertSame('Beta', $result['items'][0]['name']);
        self::assertSame('Alpha', $result['items'][1]['name']);
    }

    public function testRejectsCursorWhenContextMismatches(): void
    {
        $responder = new PaginatedCollectionResponder();
        /** @var list<array{name: string, id: int}> $records */
        $records = [
            ['name' => 'Alpha', 'id' => 1],
            ['name' => 'Beta', 'id' => 2],
            ['name' => 'Gamma', 'id' => 3],
        ];

        /** @var array{items: list<array{name: string, id: int}>, next_cursor: string|null, has_more: bool} $firstPage */
        $firstPage = $responder->paginateRecords(
            $records,
            /**
             * @param array{name: string, id: int} $record
             * @return array{name: string, id: int}
             */
            static fn(array $record): array => $record,
            'items',
            $this->buildConfig(),
            'name_asc',
            limit: 1,
            cursorContext: 'ctx:a'
        );
        self::assertIsString($firstPage['next_cursor']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid cursor.');

        $responder->paginateRecords(
            $records,
            /**
             * @param array{name: string, id: int} $record
             * @return array{name: string, id: int}
             */
            static fn(array $record): array => $record,
            'items',
            $this->buildConfig(),
            'name_asc',
            limit: 1,
            cursor: $firstPage['next_cursor'],
            cursorContext: 'ctx:b'
        );
    }

    private function buildConfig(): PaginationConfig
    {
        return new PaginationConfig(
            defaultLimit: 10,
            maxLimit: 50,
            defaultSortToken: 'name_asc',
            allowedSorts: [
                new SortSpec(
                    'name_asc',
                    new KeySpec('name', KeySpec::TYPE_STRING, SortDirection::ASC),
                    [new KeySpec('id', KeySpec::TYPE_INT, SortDirection::ASC)]
                ),
                new SortSpec(
                    'name_desc',
                    new KeySpec('name', KeySpec::TYPE_STRING, SortDirection::DESC),
                    [new KeySpec('id', KeySpec::TYPE_INT, SortDirection::DESC)]
                ),
            ]
        );
    }
}
