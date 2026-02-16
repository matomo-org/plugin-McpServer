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
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\Services\Reports\ReportProcessedQueryService;
use Piwik\Plugins\McpServer\Support\RequestScope\GetRequestScopeMutator;
use Piwik\Plugins\McpServer\Support\RequestScope\GetRequestScopeMutatorInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportProcessedQueryServiceTest extends TestCase
{
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

    public function testAppliesLimitPlusOneAndTrimsResponseData(): void
    {
        $observedFilterLimit = null;
        $observedFilterOffset = null;
        $observedApiParameters = null;
        $observedFlat = null;

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
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedFilterLimit,
                &$observedFilterOffset,
                &$observedApiParameters,
                &$observedFlat
            ): array {
                $observedFilterLimit = $_GET['filter_limit'] ?? null;
                $observedFilterOffset = $_GET['filter_offset'] ?? null;
                $observedApiParameters = $apiParameters;
                $observedFlat = $_GET['flat'] ?? null;

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

        $_GET['existing'] = 'keep';

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
        self::assertSame([], $observedApiParameters);
        self::assertSame('1', $observedFlat);
        self::assertSame('keep', $_GET['existing'] ?? null);
        self::assertArrayNotHasKey('filter_limit', $_GET);
        self::assertArrayNotHasKey('filter_offset', $_GET);
        self::assertArrayNotHasKey('flat', $_GET);

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
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedApiParameters,
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedApiParameters = $apiParameters;
                $observedGoalColumnsMode = $_GET['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals = $_GET['filter_show_goal_columns_process_goals'] ?? null;

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
        self::assertSame('-1', $observedGoalColumnsMode);
        self::assertSame('1,2', $observedGoalColumnsProcessGoals);
    }

    public function testInjectsScopedRequestParametersThroughMutator(): void
    {
        $mutator = new class () implements GetRequestScopeMutatorInterface {
            /**
             * @var array<string, mixed>|null
             */
            public ?array $capturedScopedParameters = null;

            /**
             * @param array<string, mixed> $parameters
             */
            public function runWithParameters(array $parameters, callable $callback): mixed
            {
                $this->capturedScopedParameters = $parameters;
                return $callback();
            }
        };

        $service = $this->makeService(
            $this->makeMetadataWrapper(),
            function (): array {
                return [
                    'reportData' => [['label' => 'A']],
                    'reportMetadata' => [['idsubdatatable' => 1]],
                    'columns' => ['label' => 'Label'],
                ];
            },
            $mutator
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
            'filter_limit' => '2',
            'filter_offset' => '5',
            'flat' => '1',
            'filter_update_columns_when_show_all_goals' => '-1',
            'filter_show_goal_columns_process_goals' => '1,2',
        ], $mutator->capturedScopedParameters);
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
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedGoalColumnsMode = $_GET['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals = $_GET['filter_show_goal_columns_process_goals'] ?? null;

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
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedIdGoal,
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedIdGoal = $idGoal;
                $observedGoalColumnsMode = $_GET['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals = $_GET['filter_show_goal_columns_process_goals'] ?? null;

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
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedIdGoal,
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedIdGoal = $idGoal;
                $observedGoalColumnsMode = $_GET['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals = $_GET['filter_show_goal_columns_process_goals'] ?? null;

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
                int|string|null $idGoal,
                ?int $idDimension,
                ?int $idSubtable
            ) use (
                &$observedGoalColumnsMode,
                &$observedGoalColumnsProcessGoals
            ): array {
                $observedGoalColumnsMode = $_GET['filter_update_columns_when_show_all_goals'] ?? null;
                $observedGoalColumnsProcessGoals = $_GET['filter_show_goal_columns_process_goals'] ?? null;

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

    private function makeService(
        ?ReportMetadataQueryServiceInterface $metadataWrapper = null,
        ?callable $processedReportCaller = null,
        ?GetRequestScopeMutatorInterface $mutator = null
    ): ReportProcessedQueryService {
        $metadataWrapper = $metadataWrapper ?? $this->makeMetadataWrapper();
        $mutator = $mutator ?? new GetRequestScopeMutator();
        $apiGateway = new class () implements CoreApiModuleGatewayInterface {
            public function getProcessedReport(
                int $idSite,
                string $period,
                string $date,
                string $apiModule,
                string $apiAction,
                ?string $segment,
                array $apiParameters,
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

        return new ReportProcessedQueryService(
            $metadataWrapper,
            $mutator,
            $apiGateway,
            $translatorRunner,
            $processedReportCaller
        );
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
