<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Reports;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Access;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreApiModuleGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\StrictSegmentPolicyServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\Services\Reports\ReportProcessedQueryService;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;
use Piwik\Plugins\McpServer\Support\Errors\InfrastructureDataException;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportProcessedQueryServiceTest extends TestCase
{
    private const STRICT_SEGMENT_ERROR_MESSAGE =
        'Segment is not allowed in this Matomo configuration: only existing pre-archived segments can be used. '
        . 'Use matomo_segment_list to select a saved segment definition.';

    public function testRejectsDangerousApiParameterKey(): void
    {
        $service = $this->makeService($this->makeMetadataWrapper());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Unsupported apiParameters key 'method'.");

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: ['method' => 'VisitsSummary.get'],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 50,
            filterOffset: 0
        );
    }

    public function testUniqueIdSelectorRejectsReportSpecificApiParameters(): void
    {
        $service = $this->makeService($this->makeMetadataWrapper());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid apiParameters for reportUniqueId lookup.');

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: ['idGoal' => '1'],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 50,
            filterOffset: 0
        );
    }

    public function testRejectsInvalidPeriodDateBeforeUniqueIdLookupAndFetch(): void
    {
        $processedFetchCalls = 0;

        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
            public int $calls = 0;

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                $this->calls++;

                return new ReportMetadataRecord(
                    uniqueId: $reportUniqueId,
                    module: 'Actions',
                    action: 'getPageUrls',
                    name: 'Page URLs',
                    category: 'Actions',
                    parameters: [],
                    metadata: []
                );
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date
            ): ReportMetadataRecord {
                $this->calls++;

                return new ReportMetadataRecord(
                    uniqueId: $apiModule . '_' . $apiAction,
                    module: $apiModule,
                    action: $apiAction,
                    name: 'Report',
                    category: 'Category',
                    parameters: $apiParameters,
                    metadata: []
                );
            }
        };

        $service = $this->makeService(
            $wrapper,
            function (
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (&$processedFetchCalls): array {
                $processedFetchCalls++;

                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid period/date parameters.');

        try {
            $service->getProcessedReport(
                idSite: 1,
                period: 'invalid_period',
                date: 'today',
                reportUniqueId: 'Actions_getPageUrls',
                apiModule: null,
                apiAction: null,
                apiParameters: [],
                goalMetricsMode: null,
                goalMetricsProcessGoals: null,
                segment: null,
                idGoal: null,
                idDimension: null,
                idSubtable: null,
                filterLimit: 50,
                filterOffset: 0
            );
        } finally {
            self::assertSame(0, $wrapper->calls);
            self::assertSame(0, $processedFetchCalls);
        }
    }

    public function testRejectsInvalidPeriodDateBeforeModuleActionLookupAndFetch(): void
    {
        $processedFetchCalls = 0;

        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
            public int $calls = 0;

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                $this->calls++;

                return new ReportMetadataRecord(
                    uniqueId: $reportUniqueId,
                    module: 'Actions',
                    action: 'getPageUrls',
                    name: 'Page URLs',
                    category: 'Actions',
                    parameters: [],
                    metadata: []
                );
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date
            ): ReportMetadataRecord {
                $this->calls++;

                return new ReportMetadataRecord(
                    uniqueId: $apiModule . '_' . $apiAction,
                    module: $apiModule,
                    action: $apiAction,
                    name: 'Report',
                    category: 'Category',
                    parameters: $apiParameters,
                    metadata: []
                );
            }
        };

        $service = $this->makeService(
            $wrapper,
            function (
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (&$processedFetchCalls): array {
                $processedFetchCalls++;

                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid period/date parameters.');

        try {
            $service->getProcessedReport(
                idSite: 1,
                period: 'invalid_period',
                date: 'today',
                reportUniqueId: null,
                apiModule: 'Actions',
                apiAction: 'getPageUrls',
                apiParameters: [],
                goalMetricsMode: null,
                goalMetricsProcessGoals: null,
                segment: null,
                idGoal: null,
                idDimension: null,
                idSubtable: null,
                filterLimit: 50,
                filterOffset: 0
            );
        } finally {
            self::assertSame(0, $wrapper->calls);
            self::assertSame(0, $processedFetchCalls);
        }
    }

    public function testAppliesLimitPlusOneAndTrimsResponseData(): void
    {
        $observedFilterLimit = null;
        $observedFilterOffset = null;
        $observedRequestParameters = null;
        $observedApiParameters = null;

        $service = $this->makeService(
            $this->makeMetadataWrapper(),
            function (
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedFilterLimit,
                &$observedFilterOffset,
                &$observedRequestParameters,
                &$observedApiParameters
            ): array {
                $observedFilterLimit = $requestParameters['filter_limit'] ?? null;
                $observedFilterOffset = $requestParameters['filter_offset'] ?? null;
                $observedRequestParameters = $requestParameters;
                $observedApiParameters = $apiParameters;

                return [
                    'reportData' => [
                        ['label' => 'A', 'nb_visits' => 10],
                        ['label' => 'B', 'nb_visits' => 9],
                    ],
                    'reportMetadata' => [
                        ['idsubdatatable' => 1],
                        ['idsubdatatable' => 2],
                    ],
                    'columns' => ['label' => 'Label', 'nb_visits' => 'Visits'],
                ];
            }
        );

        $record = $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: ['flat' => '1'],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 1,
            filterOffset: 5
        );

        self::assertSame('2', $observedFilterLimit);
        self::assertSame('5', $observedFilterOffset);
        self::assertSame('1', $observedRequestParameters['idSite'] ?? null);
        self::assertSame('day', $observedRequestParameters['period'] ?? null);
        self::assertSame('today', $observedRequestParameters['date'] ?? null);
        self::assertSame([], $observedApiParameters);
        self::assertSame('1', $observedRequestParameters['flat'] ?? null);

        $actual = $record->toArray();
        self::assertSame(1, $actual['pagination']['filter_limit']);
        self::assertSame(5, $actual['pagination']['filter_offset']);
        self::assertSame(1, $actual['pagination']['returned_rows']);
        self::assertTrue($actual['pagination']['has_more']);
        $report = $actual['report'];
        $reportData = $report['reportData'] ?? null;
        $reportMetadata = $report['reportMetadata'] ?? null;
        self::assertIsArray($reportData);
        self::assertIsArray($reportMetadata);
        self::assertCount(1, $reportData);
        self::assertCount(1, $reportMetadata);
    }

    public function testTopLevelGoalParametersAreMappedAndOverride(): void
    {
        $observedApiParameters = null;
        $observedRequestParameters = null;
        $observedGoalColumnsMode = null;
        $observedGoalColumnsProcessGoals = null;

        $service = $this->makeService(
            $this->makeMetadataWrapper(),
            function (
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedApiParameters,
                &$observedRequestParameters,
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedApiParameters = $apiParameters;
                $observedRequestParameters = $requestParameters;
                $observedGoalColumnsMode =
                    $requestParameters['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals =
                    $requestParameters['filter_show_goal_columns_process_goals'] ?? null;

                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: 'overview',
            goalMetricsProcessGoals: [1, '2'],
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );

        self::assertSame([], $observedApiParameters);
        self::assertSame('1', $observedRequestParameters['idSite'] ?? null);
        self::assertSame('-1', $observedGoalColumnsMode);
        self::assertSame('1,2', $observedGoalColumnsProcessGoals);
    }

    public function testPassesResolvedRequestParametersToProcessedReportCall(): void
    {
        $capturedRequestParameters = null;

        $service = $this->makeService(
            $this->makeMetadataWrapper(),
            function (
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (&$capturedRequestParameters): array {
                $capturedRequestParameters = $requestParameters;

                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: ['flat' => '1'],
            goalMetricsMode: 'overview',
            goalMetricsProcessGoals: [1, '2'],
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 1,
            filterOffset: 5
        );

        self::assertSame([
            'idSite' => '1',
            'period' => 'day',
            'date' => 'today',
            'filter_limit' => '2',
            'filter_offset' => '5',
            'flat' => '1',
            'filter_update_columns_when_show_all_goals' => '-1',
            'filter_show_goal_columns_process_goals' => '1,2',
        ], $capturedRequestParameters);
    }

    public function testRejectsGoalModeCoreFilterInApiParameters(): void
    {
        $service = $this->makeService($this->makeMetadataWrapper());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(
            "Use top-level goal parameters instead of apiParameters key 'filter_update_columns_when_show_all_goals'."
        );

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: ['filter_update_columns_when_show_all_goals' => '1'],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testRejectsInvalidGoalMetricsModeValue(): void
    {
        $service = $this->makeService($this->makeMetadataWrapper());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Invalid goalMetricsMode value 'invalid'.");

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: 'invalid',
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testSpecificGoalModeRequiresIdGoal(): void
    {
        $service = $this->makeService($this->makeMetadataWrapper());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("goalMetricsMode 'specific_goal' requires idGoal");

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: 'specific_goal',
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testRejectsEmptyGoalMetricsProcessGoals(): void
    {
        $service = $this->makeService($this->makeMetadataWrapper());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(
            'Invalid goalMetricsProcessGoals value: at least one goal ID is required.'
        );

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: [],
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testSpecificGoalModeAcceptsCoreEcommerceIdGoal(): void
    {
        $observedGoalColumnsMode = null;
        $observedGoalColumnsProcessGoals = null;

        $service = $this->makeService(
            $this->makeMetadataWrapper(),
            function (
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedGoalColumnsMode =
                    $requestParameters['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals =
                    $requestParameters['filter_show_goal_columns_process_goals'] ?? null;

                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: 'specific_goal',
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: 'ecommerceOrder',
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );

        self::assertSame('ecommerceOrder', $observedGoalColumnsMode);
        self::assertSame('ecommerceOrder', $observedGoalColumnsProcessGoals);
    }

    public function testSpecificGoalModeForNonGoalParameterizedReportDoesNotPassIdGoalToCore(): void
    {
        $observedIdGoal = null;
        $observedGoalColumnsMode = null;
        $observedGoalColumnsProcessGoals = null;

        $service = $this->makeService(
            $this->makeMetadataWrapper(),
            function (
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedIdGoal,
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedIdGoal = $idGoal;
                $observedGoalColumnsMode =
                    $requestParameters['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals =
                    $requestParameters['filter_show_goal_columns_process_goals'] ?? null;

                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: 'specific_goal',
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: 'ecommerceOrder',
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );

        self::assertNull($observedIdGoal);
        self::assertSame('ecommerceOrder', $observedGoalColumnsMode);
        self::assertSame('ecommerceOrder', $observedGoalColumnsProcessGoals);
    }

    public function testSpecificGoalModeForGoalParameterizedReportPassesIdGoalToCoreAndSkipsGoalFilters(): void
    {
        $observedIdGoal = null;
        $observedGoalColumnsMode = null;
        $observedGoalColumnsProcessGoals = null;

        $service = $this->makeService(
            $this->makeMetadataWrapperWithIdGoalParameter(),
            function (
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedIdGoal,
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedIdGoal = $idGoal;
                $observedGoalColumnsMode =
                    $requestParameters['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals =
                    $requestParameters['filter_show_goal_columns_process_goals'] ?? null;

                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $record = $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Goals_get',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: 'specific_goal',
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: 7,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );

        self::assertSame(7, $observedIdGoal);
        self::assertNull($observedGoalColumnsMode);
        self::assertNull($observedGoalColumnsProcessGoals);
        self::assertSame('1', $record->toArray()['resolvedReport']['apiParameters']['idGoal'] ?? null);
    }

    public function testGoalMetricsProcessGoalsAcceptsCoreEcommerceGoalIds(): void
    {
        $observedGoalColumnsMode = null;
        $observedGoalColumnsProcessGoals = null;

        $service = $this->makeService(
            $this->makeMetadataWrapper(),
            function (
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedGoalColumnsMode =
                    $requestParameters['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals =
                    $requestParameters['filter_show_goal_columns_process_goals'] ?? null;

                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: 'full',
            goalMetricsProcessGoals: ['ecommerceOrder', 'ecommerceAbandonedCart', 1],
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );

        self::assertSame('0', $observedGoalColumnsMode);
        self::assertSame('ecommerceOrder,ecommerceAbandonedCart,1', $observedGoalColumnsProcessGoals);
    }

    public function testRejectsSnakeCaseEcommerceGoalAliasInProcessGoals(): void
    {
        $service = $this->makeService($this->makeMetadataWrapper());

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('core ecommerce goal ID');

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: 'full',
            goalMetricsProcessGoals: ['ecommerce_order'],
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testDoesNotInjectTopLevelIdGoalIntoPrimaryMetadataLookup(): void
    {
        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
            public int $calls = 0;

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                return new ReportMetadataRecord(
                    uniqueId: $reportUniqueId,
                    module: 'Actions',
                    action: 'getPageUrls',
                    name: 'Page URLs',
                    category: 'Actions',
                    parameters: [],
                    metadata: []
                );
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date
            ): ReportMetadataRecord {
                $this->calls++;
                if (array_key_exists('idGoal', $apiParameters)) {
                    throw new ToolCallException('Unexpected idGoal in primary metadata lookup.');
                }

                return new ReportMetadataRecord(
                    uniqueId: 'Actions_getPageUrls',
                    module: $apiModule,
                    action: $apiAction,
                    name: 'Report',
                    category: 'Category',
                    parameters: $apiParameters,
                    metadata: []
                );
            }
        };

        $service = $this->makeService(
            $wrapper,
            function (): array {
                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: null,
            apiModule: 'Actions',
            apiAction: 'getPageUrls',
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: 'ecommerceOrder',
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );

        self::assertSame(1, $wrapper->calls);
    }

    public function testRetriesMetadataLookupWithTopLevelSelectorsWhenInitialLookupNotFound(): void
    {
        $wrapper = new class () implements ReportMetadataQueryServiceInterface {
            public int $calls = 0;

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                return new ReportMetadataRecord(
                    uniqueId: $reportUniqueId,
                    module: 'Actions',
                    action: 'getPageUrls',
                    name: 'Page URLs',
                    category: 'Actions',
                    parameters: [],
                    metadata: []
                );
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date
            ): ReportMetadataRecord {
                $this->calls++;
                if (!array_key_exists('idGoal', $apiParameters)) {
                    throw new ToolCallException('Report not found.');
                }

                return new ReportMetadataRecord(
                    uniqueId: 'Actions_getPageUrls',
                    module: $apiModule,
                    action: $apiAction,
                    name: 'Report',
                    category: 'Category',
                    parameters: $apiParameters,
                    metadata: []
                );
            }
        };

        $service = $this->makeService(
            $wrapper,
            function (): array {
                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        );

        $record = $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: null,
            apiModule: 'Actions',
            apiAction: 'getPageUrls',
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: 'ecommerceOrder',
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );

        self::assertSame(2, $wrapper->calls);
        self::assertSame('ecommerceOrder', $record->toArray()['resolvedReport']['apiParameters']['idGoal'] ?? null);
    }

    public function testSanitizesProcessedReportFailureMessage(): void
    {
        $access = Access::getInstance();
        $wasSuperUser = $access->hasSuperUserAccess();
        $access->setSuperUserAccess(true);

        $service = $this->makeService(
            $this->makeMetadataWrapper(),
            function (): array {
                throw new \RuntimeException("core\nfailed");
            }
        );

        try {
            $this->expectException(ToolCallException::class);
            $this->expectExceptionMessage('Report retrieval failed.');

            $service->getProcessedReport(
                idSite: 1,
                period: 'day',
                date: 'today',
                reportUniqueId: 'Actions_getPageUrls',
                apiModule: null,
                apiAction: null,
                apiParameters: [],
                goalMetricsMode: null,
                goalMetricsProcessGoals: null,
                segment: null,
                idGoal: null,
                idDimension: null,
                idSubtable: null,
                filterLimit: 10,
                filterOffset: 0
            );
        } finally {
            $access->setSuperUserAccess($wasSuperUser);
        }
    }

    public function testMapsInfrastructureDataFailureToInvalidReportDataMessage(): void
    {
        $service = $this->makeService(
            $this->makeMetadataWrapper(),
            function (): array {
                throw new InfrastructureDataException('bad payload');
            }
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report data is invalid.');

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: null,
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testAllowsAdHocSegmentWhenStrictModeEnabledIfCoreRequestSucceeds(): void
    {
        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            },
            strictSegmentPolicy: $this->makeStrictSegmentPolicyService(false)
        );

        $record = $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: 'countryCode==de',
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );

        $resolved = $record->toArray()['resolvedReport'];
        self::assertSame('Actions_getPageUrls', $resolved['uniqueId']);
    }

    public function testReturnsStrictGuidanceWhenStrictModeEnabledAndSegmentIsNotPreprocessed(): void
    {
        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                throw new CoreApiRequestException(
                    'core failed',
                    0,
                    new \RuntimeException('report data has not been pre-processed')
                );
            },
            strictSegmentPolicy: $this->makeStrictSegmentPolicyService(true)
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(self::STRICT_SEGMENT_ERROR_MESSAGE);

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: 'countryCode==de',
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testReturnsStrictGuidanceForEmptySegmentedSuccessWhenStrictModeEnabled(): void
    {
        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                return [
                    'reportData' => [],
                    'reportMetadata' => [],
                    'columns' => ['label' => 'Label'],
                ];
            },
            strictSegmentPolicy: $this->makeStrictSegmentPolicyService(true)
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(self::STRICT_SEGMENT_ERROR_MESSAGE);

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: 'countryCode==de',
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testKeepsEmptySegmentedSuccessWhenStrictModeEnabledAndSegmentIsPreprocessed(): void
    {
        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                return [
                    'reportData' => [],
                    'reportMetadata' => [],
                    'columns' => ['label' => 'Label'],
                ];
            },
            strictSegmentPolicy: $this->makeStrictSegmentPolicyService(false)
        );

        $record = $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: 'countryCode==de',
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );

        self::assertSame(0, $record->toArray()['pagination']['returned_rows']);
    }

    public function testReturnsStrictGuidanceWhenCoreFailureMentionsSegmentNotYetProcessedBySystem(): void
    {
        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                throw new CoreApiRequestException(
                    'core failed',
                    0,
                    new \RuntimeException(
                        'These reports have no data, because the Segment you requested has not yet been '
                        . 'processed by the system.'
                    )
                );
            },
            strictSegmentPolicy: $this->makeStrictSegmentPolicyService(true)
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(self::STRICT_SEGMENT_ERROR_MESSAGE);

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: 'countryCode==de',
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testDoesNotMapUnrelatedCoreFailureToStrictGuidanceAndSkipsPolicyEvaluation(): void
    {
        $strictSegmentPolicy = new class () implements StrictSegmentPolicyServiceInterface {
            public int $calls = 0;

            public function shouldMapToStrictSegmentGuidance(
                int $idSite,
                string $period,
                string $date,
                ?string $segment
            ): bool {
                $this->calls++;
                return true;
            }
        };

        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                throw new CoreApiRequestException('core failed', 0, new \RuntimeException('database timeout'));
            },
            strictSegmentPolicy: $strictSegmentPolicy
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report retrieval failed.');

        try {
            $service->getProcessedReport(
                idSite: 1,
                period: 'day',
                date: 'today',
                reportUniqueId: 'Actions_getPageUrls',
                apiModule: null,
                apiAction: null,
                apiParameters: [],
                goalMetricsMode: null,
                goalMetricsProcessGoals: null,
                segment: 'countryCode==de',
                idGoal: null,
                idDimension: null,
                idSubtable: null,
                filterLimit: 10,
                filterOffset: 0
            );
        } finally {
            self::assertSame(0, $strictSegmentPolicy->calls);
        }
    }

    public function testKeepsGenericFailureWhenSegmentIsPreprocessedInStrictMode(): void
    {
        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                throw new CoreApiRequestException(
                    'core failed',
                    0,
                    new \RuntimeException('report data has not been pre-processed')
                );
            },
            strictSegmentPolicy: $this->makeStrictSegmentPolicyService(false)
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report retrieval failed.');

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: 'countryCode==de',
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testKeepsGenericFailureWhenEligibilityCheckFails(): void
    {
        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                throw new CoreApiRequestException(
                    'core failed',
                    0,
                    new \RuntimeException('report data has not been pre-processed')
                );
            },
            strictSegmentPolicy: $this->makeStrictSegmentPolicyService(
                false,
                new \RuntimeException('boom')
            )
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report retrieval failed.');

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: 'countryCode==de',
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testDoesNotApplyStrictGuidanceForNonGatewayToolCallException(): void
    {
        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                throw new ToolCallException('Original report failure.');
            },
            strictSegmentPolicy: $this->makeStrictSegmentPolicyService(true)
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Original report failure.');

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: 'countryCode==de',
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    public function testDoesNotApplyStrictGuidanceWhenStrictModeDisabled(): void
    {
        $service = $this->makeService(
            metadataWrapper: $this->makeMetadataWrapper(),
            processedReportCaller: static function (): array {
                throw new CoreApiRequestException(
                    'core failed',
                    0,
                    new \RuntimeException('report data has not been pre-processed')
                );
            },
            strictSegmentPolicy: $this->makeStrictSegmentPolicyService(false)
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report retrieval failed.');

        $service->getProcessedReport(
            idSite: 1,
            period: 'day',
            date: 'today',
            reportUniqueId: 'Actions_getPageUrls',
            apiModule: null,
            apiAction: null,
            apiParameters: [],
            goalMetricsMode: null,
            goalMetricsProcessGoals: null,
            segment: 'countryCode==de',
            idGoal: null,
            idDimension: null,
            idSubtable: null,
            filterLimit: 10,
            filterOffset: 0
        );
    }

    private function makeService(
        ?ReportMetadataQueryServiceInterface $metadataWrapper = null,
        ?callable $processedReportCaller = null,
        ?CoreApiModuleGatewayInterface $apiGateway = null,
        ?StrictSegmentPolicyServiceInterface $strictSegmentPolicy = null
    ): ReportProcessedQueryService {
        $metadataWrapper = $metadataWrapper ?? $this->makeMetadataWrapper();
        $apiGateway = $apiGateway ?? new class () implements CoreApiModuleGatewayInterface {
            public function getProcessedReport(
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
                array $requestParameters,
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ): array {
                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            }
        };
        $translatorRunner = new class () implements TranslatorContextRunnerInterface {
            public function runInEnglish(callable $callback): mixed
            {
                return $callback();
            }
        };
        $strictSegmentPolicy = $strictSegmentPolicy ?? $this->makeStrictSegmentPolicyService(false);

        return new ReportProcessedQueryService(
            $metadataWrapper,
            $apiGateway,
            $translatorRunner,
            $strictSegmentPolicy,
            $processedReportCaller
        );
    }

    private function makeStrictSegmentPolicyService(
        bool $shouldMapToStrictGuidance,
        ?\Throwable $throwable = null
    ): StrictSegmentPolicyServiceInterface {
        return new class ($shouldMapToStrictGuidance, $throwable) implements StrictSegmentPolicyServiceInterface {
            public function __construct(
                private bool $shouldMapToStrictGuidance,
                private ?\Throwable $throwable
            ) {
            }

            public function shouldMapToStrictSegmentGuidance(
                int $idSite,
                string $period,
                string $date,
                ?string $segment
            ): bool {
                if ($this->throwable !== null) {
                    throw $this->throwable;
                }

                return $this->shouldMapToStrictGuidance;
            }
        };
    }

    private function makeMetadataWrapper(): ReportMetadataQueryServiceInterface
    {
        return new class () implements ReportMetadataQueryServiceInterface {
            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                return new ReportMetadataRecord(
                    uniqueId: $reportUniqueId,
                    module: 'Actions',
                    action: 'getPageUrls',
                    name: 'Page URLs',
                    category: 'Actions',
                    parameters: [],
                    metadata: []
                );
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date
            ): ReportMetadataRecord {
                return new ReportMetadataRecord(
                    uniqueId: $apiModule . '_' . $apiAction,
                    module: $apiModule,
                    action: $apiAction,
                    name: 'Report',
                    category: 'Category',
                    parameters: $apiParameters,
                    metadata: []
                );
            }
        };
    }

    private function makeMetadataWrapperWithIdGoalParameter(): ReportMetadataQueryServiceInterface
    {
        return new class () implements ReportMetadataQueryServiceInterface {
            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): ReportMetadataRecord
            {
                return new ReportMetadataRecord(
                    uniqueId: $reportUniqueId,
                    module: 'Goals',
                    action: 'get',
                    name: 'Goal report',
                    category: 'Goals',
                    parameters: ['idGoal' => '1'],
                    metadata: []
                );
            }

            public function getReportMetadataByModuleAction(
                int $idSite,
                string $apiModule,
                string $apiAction,
                array $apiParameters,
                string $period,
                string $date
            ): ReportMetadataRecord {
                return new ReportMetadataRecord(
                    uniqueId: $apiModule . '_' . $apiAction . '_idGoal--1',
                    module: $apiModule,
                    action: $apiAction,
                    name: 'Goal report',
                    category: 'Goals',
                    parameters: ['idGoal' => '1'],
                    metadata: []
                );
            }
        };
    }
}
