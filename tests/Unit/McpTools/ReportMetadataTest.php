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

        $actual = (new ReportMetadata($wrapper))->get(idSite: 1, reportUniqueId: 'Actions_getPageUrls');

        self::assertSame('Actions_getPageUrls', $actual['uniqueId']);
        self::assertSame('Actions', $actual['module']);
        self::assertSame('getPageUrls', $actual['action']);
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

        (new ReportMetadata($wrapper))->get(idSite: 7, apiModule: 'VisitsSummary', apiAction: 'get');

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

        $actual = (new ReportMetadata($wrapper))->get(
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

        $actual = (new ReportMetadata($wrapper))->get(
            idSite: 1,
            reportUniqueId: 'Actions_getPageUrls',
            apiParameters: [],
        );

        self::assertSame('Actions_getPageUrls', $actual['uniqueId']);
        self::assertSame('Actions_getPageUrls', $wrapper->captured['reportUniqueId']);
    }

    public function testGetRejectsUniqueIdCombinedWithModuleActionOrApiParameters(): void
    {
        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
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
                throw new \RuntimeException('unexpected');
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid parameter combination: reportUniqueId cannot be combined');

        (new ReportMetadata($wrapper))->get(
            idSite: 1,
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: 'Actions',
        );
    }

    public function testGetRejectsNonEmptyListApiParameters(): void
    {
        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
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
                throw new \RuntimeException('unexpected');
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('apiParameters is invalid.');

        $tool = new ReportMetadata($wrapper);
        $method = new \ReflectionMethod($tool, 'get');

        $method->invokeArgs($tool, [
            'idSite' => 1,
            'apiModule' => 'VisitsSummary',
            'apiAction' => 'get',
            'apiParameters' => ['flat'],
        ]);
    }
}
