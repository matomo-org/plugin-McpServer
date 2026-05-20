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
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportMetadataTest extends TestCase
{
    public function testGetReturnsRecordByUniqueId(): void
    {
        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                return new ReportMetadataRecord(
                    uniqueId: $reportUniqueId,
                    module: 'Actions',
                    action: 'getPageUrls',
                    name: 'Page URLs',
                    category: 'Actions',
                    parameters: [],
                    metadata: ['uniqueId' => $reportUniqueId, 'module' => 'Actions', 'action' => 'getPageUrls'],
                );
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date,
            ): ReportMetadataRecord {
                throw new \RuntimeException('unexpected');
            }
        };

        $actual = (new ReportMetadata($wrapper))->execute(idSite: 1, reportUniqueId: 'Actions_getPageUrls');

        self::assertSame('Actions_getPageUrls', $actual['uniqueId']);
        self::assertSame('Actions', $actual['module']);
        self::assertSame('getPageUrls', $actual['action']);
        self::assertSame(false, $actual['isSubtableReport']);
        self::assertSame(null, $actual['actionToLoadSubTables']);
    }

    public function testGetReturnsRecordByModuleActionWithDefaults(): void
    {
        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                throw new \RuntimeException('unexpected');
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date,
            ): ReportMetadataRecord {
                $this->captured = [
                    'idSite' => $idSite,
                    'apiModule' => $apiModule,
                    'apiAction' => $apiAction,
                    'apiParameters' => $apiParameters,
                    'period' => $period,
                    'date' => $date,
                ];

                return new ReportMetadataRecord(
                    uniqueId: 'VisitsSummary_get',
                    module: $apiModule,
                    action: $apiAction,
                    name: 'Overview',
                    category: 'Visits Summary',
                    parameters: $apiParameters,
                    metadata: ['module' => $apiModule, 'action' => $apiAction],
                );
            }
        };

        (new ReportMetadata($wrapper))->execute(idSite: 7, apiModule: 'VisitsSummary', apiAction: 'get');

        self::assertSame(7, $wrapper->captured['idSite']);
        self::assertSame('VisitsSummary', $wrapper->captured['apiModule']);
        self::assertSame('get', $wrapper->captured['apiAction']);
        self::assertSame([], $wrapper->captured['apiParameters']);
        self::assertSame('day', $wrapper->captured['period']);
        self::assertSame('today', $wrapper->captured['date']);
    }

    public function testGetAllowsUniqueIdCombinedWithPeriodDate(): void
    {
        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                $this->captured = ['idSite' => $idSite, 'reportUniqueId' => $reportUniqueId];

                return new ReportMetadataRecord(
                    uniqueId: $reportUniqueId,
                    module: 'Actions',
                    action: 'getPageUrls',
                    name: 'Page URLs',
                    category: 'Actions',
                    parameters: [],
                    metadata: ['uniqueId' => $reportUniqueId],
                );
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date,
            ): ReportMetadataRecord {
                throw new \RuntimeException('unexpected');
            }
        };

        $actual = (new ReportMetadata($wrapper))->execute(
            idSite: 1,
            reportUniqueId: 'Actions_getPageUrls',
            period: 'week',
            date: 'today',
        );

        self::assertSame('Actions_getPageUrls', $actual['uniqueId']);
        self::assertSame(1, $wrapper->captured['idSite']);
        self::assertSame('Actions_getPageUrls', $wrapper->captured['reportUniqueId']);
    }

    public function testGetAllowsUniqueIdCombinedWithEmptyListApiParameters(): void
    {
        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                $this->captured = ['idSite' => $idSite, 'reportUniqueId' => $reportUniqueId];

                return new ReportMetadataRecord(
                    uniqueId: $reportUniqueId,
                    module: 'Actions',
                    action: 'getPageUrls',
                    name: 'Page URLs',
                    category: 'Actions',
                    parameters: [],
                    metadata: ['uniqueId' => $reportUniqueId],
                );
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date,
            ): ReportMetadataRecord {
                throw new \RuntimeException('unexpected');
            }
        };

        $actual = (new ReportMetadata($wrapper))->execute(
            idSite: 1,
            reportUniqueId: 'Actions_getPageUrls',
            apiParameters: [],
        );

        self::assertSame('Actions_getPageUrls', $actual['uniqueId']);
        self::assertSame('Actions_getPageUrls', $wrapper->captured['reportUniqueId']);
    }

    public function testGetAcceptsNestedObjectApiParameters(): void
    {
        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
            /** @var array<string, mixed> */
            public array $captured = [];

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                throw new \RuntimeException('unexpected');
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date,
            ): ReportMetadataRecord {
                $this->captured = ['apiParameters' => $apiParameters];

                return new ReportMetadataRecord(
                    uniqueId: 'Actions_getPageUrls',
                    module: $apiModule,
                    action: $apiAction,
                    name: 'Page URLs',
                    category: 'Actions',
                    parameters: $apiParameters,
                    metadata: ['module' => $apiModule, 'action' => $apiAction],
                );
            }
        };

        (new ReportMetadata($wrapper))->execute(
            idSite: 1,
            apiModule: 'Actions',
            apiAction: 'getPageUrls',
            apiParameters: ['filters' => ['segment' => 'countryCode==de']],
        );

        self::assertSame(['filters' => ['segment' => 'countryCode==de']], $wrapper->captured['apiParameters']);
    }
}
