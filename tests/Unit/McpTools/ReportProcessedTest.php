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
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportProcessedRecord;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportProcessedTest extends TestCase
{
    public function testGetUsesDefaultsAndUniqueIdSelector(): void
    {
        $wrapper = new class () implements ReportProcessedQueryServiceInterface {
            /** @var array<string, mixed> */
            public array $captured = [];

            /**
             * @param list<int|string>|null $goalMetricsProcessGoals
             */
            public function getProcessedReport(
                int $idSite,
                string $period,
                string $date,
                ?string $reportUniqueId,
                ?string $apiModule,
                ?string $apiAction,
                ?array $apiParameters,
                ?string $goalMetricsMode,
                ?array $goalMetricsProcessGoals,
                ?string $segment,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable,
                int $filterLimit,
                int $filterOffset
            ): ReportProcessedRecord {
                $this->captured = [
                    'idSite' => $idSite,
                    'period' => $period,
                    'date' => $date,
                    'reportUniqueId' => $reportUniqueId,
                    'apiModule' => $apiModule,
                    'apiAction' => $apiAction,
                    'apiParameters' => $apiParameters,
                    'goalMetricsMode' => $goalMetricsMode,
                    'goalMetricsProcessGoals' => $goalMetricsProcessGoals,
                    'segment' => $segment,
                    'idGoal' => $idGoal,
                    'idDimension' => $idDimension,
                    'idSubtable' => $idSubtable,
                    'filterLimit' => $filterLimit,
                    'filterOffset' => $filterOffset,
                ];

                return new ReportProcessedRecord(
                    report: [],
                    filterLimit: $filterLimit,
                    filterOffset: $filterOffset,
                    returnedRows: 0,
                    hasMore: false,
                    uniqueId: (string) $reportUniqueId,
                    apiModule: 'Actions',
                    apiAction: 'getPageUrls',
                    apiParameters: []
                );
            }
        };

        $actual = (new ReportProcessed($wrapper))->get(
            idSite: 3,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls'
        );

        self::assertSame(ToolOutputSecurity::buildForTool(ReportProcessed::TOOL_NAME), $actual['security'] ?? null);
        self::assertSame(3, $wrapper->captured['idSite']);
        self::assertSame('day', $wrapper->captured['period']);
        self::assertSame('today', $wrapper->captured['date']);
        self::assertSame('Actions_getPageUrls', $wrapper->captured['reportUniqueId']);
        self::assertSame(50, $wrapper->captured['filterLimit']);
        self::assertSame(0, $wrapper->captured['filterOffset']);
    }

    public function testGetPassesOptionalArguments(): void
    {
        $wrapper = new class () implements ReportProcessedQueryServiceInterface {
            /** @var array<string, mixed> */
            public array $captured = [];

            /**
             * @param list<int|string>|null $goalMetricsProcessGoals
             */
            public function getProcessedReport(
                int $idSite,
                string $period,
                string $date,
                ?string $reportUniqueId,
                ?string $apiModule,
                ?string $apiAction,
                ?array $apiParameters,
                ?string $goalMetricsMode,
                ?array $goalMetricsProcessGoals,
                ?string $segment,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable,
                int $filterLimit,
                int $filterOffset
            ): ReportProcessedRecord {
                $this->captured = [
                    'apiModule' => $apiModule,
                    'apiAction' => $apiAction,
                    'apiParameters' => $apiParameters,
                    'goalMetricsMode' => $goalMetricsMode,
                    'goalMetricsProcessGoals' => $goalMetricsProcessGoals,
                    'segment' => $segment,
                    'idGoal' => $idGoal,
                    'idDimension' => $idDimension,
                    'idSubtable' => $idSubtable,
                    'filterLimit' => $filterLimit,
                    'filterOffset' => $filterOffset,
                ];

                return new ReportProcessedRecord(
                    report: [],
                    filterLimit: $filterLimit,
                    filterOffset: $filterOffset,
                    returnedRows: 0,
                    hasMore: false,
                    uniqueId: 'VisitsSummary_get',
                    apiModule: (string) $apiModule,
                    apiAction: (string) $apiAction,
                    apiParameters: $apiParameters ?? []
                );
            }
        };

        $actual = (new ReportProcessed($wrapper))->get(
            idSite: 3,
            period: 'week',
            date: 'today',
            apiModule: 'VisitsSummary',
            apiAction: 'get',
            apiParameters: ['flat' => '1'],
            goalMetricsMode: 'overview',
            goalMetricsProcessGoals: [1, 'ecommerceOrder'],
            segment: 'countryCode==de',
            idGoal: 'ecommerceOrder',
            idDimension: 4,
            idSubtable: 9,
            filter_limit: 25,
            filter_offset: 50
        );

        self::assertSame(ToolOutputSecurity::buildForTool(ReportProcessed::TOOL_NAME), $actual['security'] ?? null);
        self::assertSame('VisitsSummary', $wrapper->captured['apiModule']);
        self::assertSame('get', $wrapper->captured['apiAction']);
        self::assertSame(['flat' => '1'], $wrapper->captured['apiParameters']);
        self::assertSame('overview', $wrapper->captured['goalMetricsMode']);
        self::assertSame([1, 'ecommerceOrder'], $wrapper->captured['goalMetricsProcessGoals']);
        self::assertSame('countryCode==de', $wrapper->captured['segment']);
        self::assertSame('ecommerceOrder', $wrapper->captured['idGoal']);
        self::assertSame(4, $wrapper->captured['idDimension']);
        self::assertSame(9, $wrapper->captured['idSubtable']);
        self::assertSame(25, $wrapper->captured['filterLimit']);
        self::assertSame(50, $wrapper->captured['filterOffset']);
    }
}
