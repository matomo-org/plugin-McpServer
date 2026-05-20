<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\McpTools;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Segments\SegmentDetailRecord;
use Piwik\Plugins\McpServer\McpTools\SegmentGet;

/**
 * @group McpServer
 * @group Plugins
 */
class SegmentGetTest extends TestCase
{
    public function testGetReturnsRecordFromApiWrapper(): void
    {
        $wrapper = new class () implements SegmentDetailQueryServiceInterface {
            public function getSegmentDetailsForSite(int $idSite): array
            {
                return [];
            }

            public function getSegmentBySelector(
                int $idSite,
                ?int $idSegment = null,
                ?string $name = null,
                ?string $definition = null,
            ): SegmentDetailRecord {
                return new SegmentDetailRecord(
                    idSegment: 4,
                    name: 'Segment Name',
                    definition: 'countryCode==de',
                    idSite: 2,
                    autoArchive: true,
                    enabledAllUsers: false,
                    login: 'superUserLogin',
                );
            }
        };

        $actual = (new SegmentGet($wrapper))->execute(idSite: 2, idSegment: 4);

        self::assertSame([
            'idsegment' => 4,
            'name' => 'Segment Name',
            'definition' => 'countryCode==de',
            'idsite' => 2,
            'auto_archive' => true,
            'enabled_all_users' => false,
            'login' => 'superUserLogin',
        ], $actual);
    }

    public function testGetPassesSelectorsToApiWrapperWithoutRuntimeValidation(): void
    {
        $wrapper = new class () implements SegmentDetailQueryServiceInterface {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function getSegmentDetailsForSite(int $idSite): array
            {
                return [];
            }

            public function getSegmentBySelector(
                int $idSite,
                ?int $idSegment = null,
                ?string $name = null,
                ?string $definition = null,
            ): SegmentDetailRecord {
                $this->captured = [
                    'idSite' => $idSite,
                    'idSegment' => $idSegment,
                    'name' => $name,
                    'definition' => $definition,
                ];

                return new SegmentDetailRecord(
                    idSegment: 10,
                    name: 'Forwarded',
                    definition: 'browserCode==FF',
                    idSite: $idSite,
                    autoArchive: false,
                    enabledAllUsers: false,
                    login: 'forwarded',
                );
            }
        };

        (new SegmentGet($wrapper))->execute(
            idSite: 2,
            idSegment: 8,
            name: '  Segment Name  ',
            definition: '  browserCode==FF  ',
        );

        $captured = $wrapper->captured;
        self::assertSame(2, $captured['idSite']);
        self::assertSame(8, $captured['idSegment']);
        self::assertSame('  Segment Name  ', $captured['name']);
        self::assertSame('  browserCode==FF  ', $captured['definition']);
    }
}
