<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Piwik\ArchiveProcessor\Rules;
use Piwik\Cache;
use Piwik\Config;
use Piwik\Container\StaticContainer;
use Piwik\DataTable;
use Piwik\DataTable\Map;
use Piwik\DataTable\Row;
use Piwik\Plugins\API\API as ApiModuleApi;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreApiModuleGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\StrictSegmentPolicyServiceInterface;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportProcessedTest extends IntegrationTestCase
{
    private const STRICT_SEGMENT_ERROR_MESSAGE =
        'Segment is not allowed in this Matomo configuration: only existing pre-archived segments can be used. '
        . 'Use matomo_segment_list to select a saved segment definition.';

    private int $idSite = 0;

    protected static function configureFixture($fixture): void
    {
        parent::configureFixture($fixture);

        $fixture->createSuperUser = true;
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2015-01-01 00:00:00',
            0,
            'MCP Processed Report Test Site',
            'https://report-processed.test'
        );

        $tracker = Fixture::getTracker(
            $this->idSite,
            '2015-01-03 12:00:00',
            $defaultInit = true,
            $useLocal = true
        );
        $tracker->setUrl('https://report-processed.test/page-a');
        Fixture::checkResponse($tracker->doTrackPageView('page-a'));
        $tracker->setUrl('https://report-processed.test/page-b');
        Fixture::checkResponse($tracker->doTrackPageView('page-b'));
        $tracker->setUrl('https://report-processed.test/page-c');
        Fixture::checkResponse($tracker->doTrackPageView('page-c'));
    }

    public function testReturnsProcessedReportWithPaginationMetadata(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $baseline = ApiModuleApi::getInstance()->getProcessedReport(
            $this->idSite,
            'day',
            '2015-01-03',
            'Actions',
            'getPageUrls',
            false,
            [],
            false,
            false,
            false,
            true,
            false,
            false,
            null,
            false
        );
        $baselineReportData = $baseline['reportData'] ?? null;
        self::assertInstanceOf(DataTable::class, $baselineReportData);
        self::assertGreaterThanOrEqual(2, $baselineReportData->getRowsCount());

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'filter_limit' => 1,
                'filter_offset' => 0,
            ],
            __METHOD__
        );

        self::assertArrayHasKey('report', $content);
        self::assertArrayHasKey('pagination', $content);
        self::assertArrayHasKey('resolvedReport', $content);
        $pagination = $content['pagination'] ?? null;
        self::assertIsArray($pagination);
        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        self::assertSame(1, $pagination['filter_limit'] ?? null);
        self::assertSame(0, $pagination['filter_offset'] ?? null);
        self::assertSame(1, $pagination['returned_rows'] ?? null);
        self::assertGreaterThanOrEqual(2, $pagination['total_rows'] ?? 0);
        self::assertTrue($pagination['has_more'] ?? false);
        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);

        $report = $content['report'] ?? null;
        self::assertIsArray($report);
        self::assertArrayHasKey('reportData', $report);
        self::assertIsArray($report['reportData']);
        self::assertCount(1, $report['reportData']);
        if (isset($report['reportMetadata']) && is_array($report['reportMetadata'])) {
            self::assertCount(1, $report['reportMetadata']);
        }
    }

    public function testRejectsDangerousApiParametersKey(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'apiParameters' => ['method' => 'VisitsSummary.get'],
            ],
            "Unsupported apiParameters key 'method'.",
            __METHOD__
        );
    }

    public function testSerializesEmptyResolvedApiParametersAsObjectInResponseBody(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'filter_limit' => 1,
                'filter_offset' => 0,
            ],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $body = McpTestHelper::getResponseBody($response);

        self::assertStringContainsString('"resolvedReport"', $body);
        self::assertStringContainsString('"apiParameters":{}', $body);
    }

    public function testRejectsInvalidPeriodDateParameters(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'invalid_period',
                'date' => 'today',
                'reportUniqueId' => $reportUniqueId,
            ],
            'Invalid period/date parameters.',
            __METHOD__
        );
    }

    public function testRejectsMissingSelectorAtSchemaLevel(): void
    {
        $this->assertInvalidSelectorArgumentsAtSchemaLevel([]);
    }

    public function testRejectsCombinedUniqueIdAndApiModuleAtSchemaLevel(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $this->assertInvalidSelectorArgumentsAtSchemaLevel([
            'reportUniqueId' => $reportUniqueId,
            'apiModule' => 'Actions',
        ]);
    }

    public function testRejectsCombinedUniqueIdAndApiActionAtSchemaLevel(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $this->assertInvalidSelectorArgumentsAtSchemaLevel([
            'reportUniqueId' => $reportUniqueId,
            'apiAction' => 'getPageUrls',
        ]);
    }

    public function testRejectsApiModuleWithoutApiActionAtSchemaLevel(): void
    {
        $this->assertInvalidSelectorArgumentsAtSchemaLevel([
            'apiModule' => 'Actions',
        ]);
    }

    public function testRejectsApiActionWithoutApiModuleAtSchemaLevel(): void
    {
        $this->assertInvalidSelectorArgumentsAtSchemaLevel([
            'apiAction' => 'getPageUrls',
        ]);
    }

    public function testReturnsProcessedReportByModuleActionWithMonthLast3(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'month',
                'date' => 'last3',
                'apiModule' => 'Actions',
                'apiAction' => 'getPageUrls',
            ],
            __METHOD__
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);

        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);
    }

    public function testReturnsProcessedReportByModuleActionWithExplicitRangeDate(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'range',
                'date' => '2015-01-01,2015-01-31',
                'apiModule' => 'Actions',
                'apiAction' => 'getPageUrls',
            ],
            __METHOD__
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);

        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);
    }

    public function testMasksNoAccessAsNotFound(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        McpAuthTestHelper::asNoAccessUser(function () use ($reportUniqueId): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            McpTestHelper::callToolAndAssertError(
                $server,
                $sessionId,
                ReportProcessed::TOOL_NAME,
                [
                    'idSite' => $this->idSite,
                    'period' => 'day',
                    'date' => '2015-01-03',
                    'reportUniqueId' => $reportUniqueId,
                ],
                'Report not found.',
                __METHOD__
            );
        });
    }

    public function testMapsTopLevelGoalParametersToResolvedApiParameters(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'goalMetricsMode' => 'overview',
                'goalMetricsProcessGoals' => [1, '2'],
            ],
            __METHOD__
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        $resolvedApiParameters = $resolvedReport['apiParameters'] ?? null;
        self::assertIsArray($resolvedApiParameters);
        self::assertSame('-1', $resolvedApiParameters['filter_update_columns_when_show_all_goals'] ?? null);
        self::assertSame('1,2', $resolvedApiParameters['filter_show_goal_columns_process_goals'] ?? null);
    }

    public function testMapsCoreEcommerceGoalIdsToResolvedApiParameters(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'goalMetricsMode' => 'full',
                'goalMetricsProcessGoals' => ['ecommerceOrder'],
            ],
            __METHOD__
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        $resolvedApiParameters = $resolvedReport['apiParameters'] ?? null;
        self::assertIsArray($resolvedApiParameters);
        self::assertSame('0', $resolvedApiParameters['filter_update_columns_when_show_all_goals'] ?? null);
        self::assertSame('ecommerceOrder', $resolvedApiParameters['filter_show_goal_columns_process_goals'] ?? null);
    }

    public function testSpecificGoalDefaultsProcessGoalsForActionsReport(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'goalMetricsMode' => 'specific_goal',
                'idGoal' => 'ecommerceOrder',
            ],
            __METHOD__
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        $resolvedApiParameters = $resolvedReport['apiParameters'] ?? null;
        self::assertIsArray($resolvedApiParameters);
        self::assertSame('ecommerceOrder', $resolvedApiParameters['filter_update_columns_when_show_all_goals'] ?? null);
        self::assertSame('ecommerceOrder', $resolvedApiParameters['filter_show_goal_columns_process_goals'] ?? null);
    }

    public function testSpecificGoalIsSuppressedForGoalParameterizedNumericReport(): void
    {
        $idGoal = (int) GoalsApi::getInstance()->addGoal(
            $this->idSite,
            'MCP Report Processed Numeric Goal ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'event_action',
            'evt-report-processed-goal',
            'exact'
        );
        $reportSelector = $this->findGoalParameterizedReport($this->idSite, $idGoal);
        self::assertNotNull($reportSelector);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportSelector['uniqueId'],
                'goalMetricsMode' => 'specific_goal',
                'idGoal' => $idGoal,
            ],
            __METHOD__
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        $resolvedApiParameters = $resolvedReport['apiParameters'] ?? null;
        self::assertIsArray($resolvedApiParameters);
        self::assertArrayHasKey('idGoal', $resolvedApiParameters);
        self::assertSame((string) $idGoal, (string) $resolvedApiParameters['idGoal']);
        self::assertArrayNotHasKey('filter_update_columns_when_show_all_goals', $resolvedApiParameters);
        self::assertArrayNotHasKey('filter_show_goal_columns_process_goals', $resolvedApiParameters);
    }

    public function testSchemaDeclaresTopLevelGoalParameters(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-report-processed-schema');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        $tool = null;
        foreach ($result->tools as $candidate) {
            if ($candidate->name === ReportProcessed::TOOL_NAME) {
                $tool = $candidate;
                break;
            }
        }

        self::assertNotNull($tool);
        /** @var array<string, mixed> $inputSchema */
        $inputSchema = $tool->inputSchema;
        self::assertArrayNotHasKey('oneOf', $inputSchema);
        self::assertArrayNotHasKey('allOf', $inputSchema);
        self::assertArrayNotHasKey('anyOf', $inputSchema);
        self::assertArrayHasKey('not', $inputSchema);
        self::assertIsArray($inputSchema['not']);

        $properties = $inputSchema['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('goalMetricsMode', $properties);
        self::assertArrayHasKey('goalMetricsProcessGoals', $properties);
        $items = $properties['goalMetricsProcessGoals']['items']['oneOf'] ?? null;
        self::assertIsArray($items);

        $foundCoreEcommercePattern = false;
        foreach ($items as $itemSchema) {
            if (
                is_array($itemSchema)
                && ($itemSchema['type'] ?? null) === 'string'
                && is_string($itemSchema['pattern'] ?? null)
                && str_contains((string) $itemSchema['pattern'], 'ecommerceOrder')
            ) {
                $foundCoreEcommercePattern = true;
                break;
            }
        }

        self::assertTrue($foundCoreEcommercePattern);
    }

    public function testRejectsOldGoalParameterNamesAtSchemaLevel(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $error = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'goal_columns_mode' => '-1',
            ],
            __METHOD__
        );

        self::assertStringContainsString('goal_columns_mode', $error->message ?? '');
    }

    public function testAllowsUniqueIdCombinedWithEmptyListApiParameters(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'apiParameters' => [],
                'filter_limit' => 1,
                'filter_offset' => 0,
            ],
            __METHOD__
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);
    }

    public function testAllowsUniqueIdCombinedWithEmptyObjectApiParameters(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => __METHOD__,
            'method' => 'tools/call',
            'params' => [
                'name' => ReportProcessed::TOOL_NAME,
                'arguments' => [
                    'idSite' => $this->idSite,
                    'period' => 'day',
                    'date' => '2015-01-03',
                    'reportUniqueId' => $reportUniqueId,
                    'apiParameters' => new \stdClass(),
                    'filter_limit' => 1,
                    'filter_offset' => 0,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $content = McpTestHelper::assertToolSuccess(
            McpTestHelper::parseCallTool(McpTestHelper::decodeResponse($response))
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);
    }

    public function testAllowsModuleActionCombinedWithEmptyListApiParameters(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'apiModule' => 'Actions',
                'apiAction' => 'getPageUrls',
                'apiParameters' => [],
                'filter_limit' => 1,
                'filter_offset' => 0,
            ],
            __METHOD__
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);
    }

    public function testRejectsModuleActionWithNonEmptyListApiParametersAtSchemaLevel(): void
    {
        $this->assertInvalidSelectorArgumentsAtSchemaLevel([
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
            'apiParameters' => ['flat'],
        ]);
    }

    public function testReturnsStrictGuidanceForAdHocSegmentInStrictArchivingMode(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $this->runInStrictSegmentArchivingMode(function () use ($reportUniqueId): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);

            McpTestHelper::callToolAndAssertError(
                $server,
                $sessionId,
                ReportProcessed::TOOL_NAME,
                [
                    'idSite' => $this->idSite,
                    'period' => 'day',
                    'date' => '2015-01-03',
                    'reportUniqueId' => $reportUniqueId,
                    'segment' => 'countryCode==de',
                ],
                self::STRICT_SEGMENT_ERROR_MESSAGE,
                __METHOD__
            );
        });
    }

    public function testAllowsSavedAutoArchivedSegmentInStrictArchivingMode(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $this->runInStrictSegmentArchivingMode(function () use ($reportUniqueId): void {
            $segmentDefinition = 'countryCode==de';
            SegmentEditorApi::getInstance()->add(
                'MCP Strict Segment ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
                $segmentDefinition,
                $this->idSite,
                true
            );

            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            $content = McpTestHelper::callToolAndAssertSuccess(
                $server,
                $sessionId,
                ReportProcessed::TOOL_NAME,
                [
                    'idSite' => $this->idSite,
                    'period' => 'day',
                    'date' => '2015-01-03',
                    'reportUniqueId' => $reportUniqueId,
                    'segment' => $segmentDefinition,
                ],
                __METHOD__
            );

            self::assertArrayHasKey('report', $content);
            self::assertArrayHasKey('pagination', $content);
            self::assertArrayHasKey('resolvedReport', $content);
            $pagination = $content['pagination'] ?? null;
            self::assertIsArray($pagination);
            self::assertArrayHasKey('total_rows', $pagination);
        });
    }

    public function testReturnsGenericFailureForInvalidSegmentInStrictArchivingMode(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $this->runInStrictSegmentArchivingMode(function () use ($reportUniqueId): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);

            McpTestHelper::callToolAndAssertError(
                $server,
                $sessionId,
                ReportProcessed::TOOL_NAME,
                [
                    'idSite' => $this->idSite,
                    'period' => 'day',
                    'date' => '2015-01-03',
                    'reportUniqueId' => $reportUniqueId,
                    'segment' => 'notASupportedSegment==de',
                ],
                'Report retrieval failed.',
                __METHOD__
            );
        });
    }

    public function testReturnsStrictGuidanceWhenGatewayFailsWithStrictSegmentRestriction(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $container = StaticContainer::getContainer();
        $originalGateway = $container->get(CoreApiModuleGatewayInterface::class);
        $originalStrictSegmentPolicy = $container->get(StrictSegmentPolicyServiceInterface::class);

        $container->set(
            CoreApiModuleGatewayInterface::class,
            new class () implements CoreApiModuleGatewayInterface {
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
                    throw new CoreApiRequestException(
                        'Core API processed report request failed.',
                        0,
                        new \RuntimeException('report data has not been pre-processed')
                    );
                }
            }
        );
        $container->set(
            StrictSegmentPolicyServiceInterface::class,
            new class () implements StrictSegmentPolicyServiceInterface {
                public function shouldMapToStrictSegmentGuidance(
                    int $idSite,
                    string $period,
                    string $date,
                    ?string $segment
                ): bool {
                    return true;
                }
            }
        );

        try {
            McpAuthTestHelper::asViewUserForSite($this->idSite, function () use ($reportUniqueId): void {
                $server = McpTestHelper::buildServer();
                $sessionId = McpTestHelper::initializeSession($server);

                McpTestHelper::callToolAndAssertError(
                    $server,
                    $sessionId,
                    ReportProcessed::TOOL_NAME,
                    [
                        'idSite' => $this->idSite,
                        'period' => 'day',
                        'date' => '2015-01-03',
                        'reportUniqueId' => $reportUniqueId,
                        'segment' => 'countryCode==zz',
                    ],
                    self::STRICT_SEGMENT_ERROR_MESSAGE,
                    __METHOD__
                );
            });
        } finally {
            $container->set(CoreApiModuleGatewayInterface::class, $originalGateway);
            $container->set(StrictSegmentPolicyServiceInterface::class, $originalStrictSegmentPolicy);
        }
    }

    public function testReturnsStrictGuidanceWhenGatewayReturnsEmptySegmentedReportInStrictMode(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $container = StaticContainer::getContainer();
        $originalGateway = $container->get(CoreApiModuleGatewayInterface::class);
        $originalStrictSegmentPolicy = $container->get(StrictSegmentPolicyServiceInterface::class);

        $container->set(
            CoreApiModuleGatewayInterface::class,
            new class () implements CoreApiModuleGatewayInterface {
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
                    $reportData = new DataTable();
                    $reportData->setMetadata(DataTable::TOTAL_ROWS_BEFORE_LIMIT_METADATA_NAME, 0);

                    return [
                        'reportData' => $reportData,
                        'reportMetadata' => new DataTable(),
                        'columns' => ['label' => 'Label'],
                    ];
                }
            }
        );
        $container->set(
            StrictSegmentPolicyServiceInterface::class,
            new class () implements StrictSegmentPolicyServiceInterface {
                public function shouldMapToStrictSegmentGuidance(
                    int $idSite,
                    string $period,
                    string $date,
                    ?string $segment
                ): bool {
                    return true;
                }
            }
        );

        try {
            McpAuthTestHelper::asViewUserForSite($this->idSite, function () use ($reportUniqueId): void {
                $server = McpTestHelper::buildServer();
                $sessionId = McpTestHelper::initializeSession($server);

                McpTestHelper::callToolAndAssertError(
                    $server,
                    $sessionId,
                    ReportProcessed::TOOL_NAME,
                    [
                        'idSite' => $this->idSite,
                        'period' => 'day',
                        'date' => '2015-01-03',
                        'reportUniqueId' => $reportUniqueId,
                        'segment' => 'countryCode==zz',
                    ],
                    self::STRICT_SEGMENT_ERROR_MESSAGE,
                    __METHOD__
                );
            });
        } finally {
            $container->set(CoreApiModuleGatewayInterface::class, $originalGateway);
            $container->set(StrictSegmentPolicyServiceInterface::class, $originalStrictSegmentPolicy);
        }
    }

    public function testDerivesMapPaginationAcrossAllTables(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $container = StaticContainer::getContainer();
        $originalGateway = $container->get(CoreApiModuleGatewayInterface::class);

        $container->set(
            CoreApiModuleGatewayInterface::class,
            new class () implements CoreApiModuleGatewayInterface {
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
                    $tableA = new DataTable();
                    $tableA->addRow(new Row([Row::COLUMNS => ['label' => 'A1']]));
                    $tableA->addRow(new Row([Row::COLUMNS => ['label' => 'A2']]));
                    $tableA->setMetadata(DataTable::TOTAL_ROWS_BEFORE_LIMIT_METADATA_NAME, 2);

                    $tableB = new DataTable();
                    $tableB->addRow(new Row([Row::COLUMNS => ['label' => 'B1']]));
                    $tableB->addRow(new Row([Row::COLUMNS => ['label' => 'B2']]));
                    $tableB->addRow(new Row([Row::COLUMNS => ['label' => 'B3']]));
                    $tableB->setMetadata(DataTable::TOTAL_ROWS_BEFORE_LIMIT_METADATA_NAME, 10);

                    $reportData = new Map();
                    $reportData->addTable($tableA, '2015-01-01');
                    $reportData->addTable($tableB, '2015-01-02');

                    return [
                        'reportData' => $reportData,
                        'reportMetadata' => new Map(),
                        'columns' => ['label' => 'Label'],
                    ];
                }
            }
        );

        try {
            McpAuthTestHelper::asViewUserForSite($this->idSite, function () use ($reportUniqueId): void {
                $server = McpTestHelper::buildServer();
                $sessionId = McpTestHelper::initializeSession($server);
                $content = McpTestHelper::callToolAndAssertSuccess(
                    $server,
                    $sessionId,
                    ReportProcessed::TOOL_NAME,
                    [
                        'idSite' => $this->idSite,
                        'period' => 'range',
                        'date' => '2015-01-01,2015-01-02',
                        'reportUniqueId' => $reportUniqueId,
                        'filter_limit' => 10,
                        'filter_offset' => 1,
                    ],
                    __METHOD__
                );

                $pagination = $content['pagination'] ?? null;
                self::assertIsArray($pagination);
                self::assertSame(3, $pagination['returned_rows'] ?? null);
                self::assertSame(10, $pagination['total_rows'] ?? null);
                self::assertTrue($pagination['has_more'] ?? false);
            });
        } finally {
            $container->set(CoreApiModuleGatewayInterface::class, $originalGateway);
        }
    }

    public function testFallsBackForMissingMapPaginationMetadata(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $container = StaticContainer::getContainer();
        $originalGateway = $container->get(CoreApiModuleGatewayInterface::class);

        $container->set(
            CoreApiModuleGatewayInterface::class,
            new class () implements CoreApiModuleGatewayInterface {
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
                    $tableA = new DataTable();
                    $tableA->addRow(new Row([Row::COLUMNS => ['label' => 'A1']]));

                    $tableB = new DataTable();
                    $tableB->addRow(new Row([Row::COLUMNS => ['label' => 'B1']]));
                    $tableB->addRow(new Row([Row::COLUMNS => ['label' => 'B2']]));

                    $reportData = new Map();
                    $reportData->addTable($tableA, '2015-01-01');
                    $reportData->addTable($tableB, '2015-01-02');

                    return [
                        'reportData' => $reportData,
                        'reportMetadata' => new Map(),
                        'columns' => ['label' => 'Label'],
                    ];
                }
            }
        );

        try {
            McpAuthTestHelper::asViewUserForSite($this->idSite, function () use ($reportUniqueId): void {
                $server = McpTestHelper::buildServer();
                $sessionId = McpTestHelper::initializeSession($server);
                $content = McpTestHelper::callToolAndAssertSuccess(
                    $server,
                    $sessionId,
                    ReportProcessed::TOOL_NAME,
                    [
                        'idSite' => $this->idSite,
                        'period' => 'range',
                        'date' => '2015-01-01,2015-01-02',
                        'reportUniqueId' => $reportUniqueId,
                        'filter_limit' => 10,
                        'filter_offset' => 1,
                    ],
                    __METHOD__
                );

                $pagination = $content['pagination'] ?? null;
                self::assertIsArray($pagination);
                self::assertSame(2, $pagination['returned_rows'] ?? null);
                self::assertSame(2, $pagination['total_rows'] ?? null);
                self::assertFalse($pagination['has_more'] ?? true);
            });
        } finally {
            $container->set(CoreApiModuleGatewayInterface::class, $originalGateway);
        }
    }

    private function findReportUniqueId(int $idSite, string $module, string $action): ?string
    {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);

        foreach ($metadata as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->isSubtableMetadataRow($row)) {
                continue;
            }

            if (($row['module'] ?? null) !== $module || ($row['action'] ?? null) !== $action) {
                continue;
            }

            $uniqueId = $row['uniqueId'] ?? null;
            if (is_string($uniqueId) && $uniqueId !== '') {
                return $uniqueId;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $selectorArguments
     */
    private function assertInvalidSelectorArgumentsAtSchemaLevel(array $selectorArguments): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $arguments = array_merge(
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
            ],
            $selectorArguments
        );

        $error = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            $arguments,
            __METHOD__
        );

        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportProcessed::TOOL_NAME . "':",
            $error->message ?? ''
        );
    }

    /**
     * @return array{module: string, action: string, uniqueId: string}|null
     */
    private function findGoalParameterizedReport(int $idSite, int $idGoal): ?array
    {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);

        foreach ($metadata as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->isSubtableMetadataRow($row)) {
                continue;
            }

            $parameters = $row['parameters'] ?? null;
            if (!is_array($parameters)) {
                continue;
            }

            $rowIdGoal = $parameters['idGoal'] ?? null;
            if (is_int($rowIdGoal)) {
                $rowIdGoal = (string) $rowIdGoal;
            }
            if (!is_string($rowIdGoal) || $rowIdGoal !== (string) $idGoal) {
                continue;
            }

            $module = $row['module'] ?? null;
            $action = $row['action'] ?? null;
            $uniqueId = $row['uniqueId'] ?? null;
            if (
                is_string($module) && $module !== ''
                && is_string($action) && $action !== ''
                && is_string($uniqueId) && $uniqueId !== ''
            ) {
                return [
                    'module' => $module,
                    'action' => $action,
                    'uniqueId' => $uniqueId,
                ];
            }
        }

        return null;
    }

    /**
     * @param array<mixed> $row
     */
    private function isSubtableMetadataRow(array $row): bool
    {
        $primary = $row['isSubtableReport'] ?? null;
        if ($primary === true || $primary === 1 || $primary === '1') {
            return true;
        }

        $alias = $row['isSubtableReports'] ?? null;
        return $alias === true || $alias === 1 || $alias === '1';
    }

    private function runInStrictSegmentArchivingMode(callable $callback): void
    {
        $config = Config::getInstance();
        $general = $config->General;
        if (!is_array($general)) {
            throw new \RuntimeException('Invalid Matomo general config state.');
        }

        $originalEnableBrowserArchivingTriggering = (int) ($general['enable_browser_archiving_triggering'] ?? 1);
        $originalBrowserArchivingDisabledEnforce = (int) ($general['browser_archiving_disabled_enforce'] ?? 0);
        $originalBrowserTriggerEnabled = Rules::isBrowserTriggerEnabled();

        try {
            $general['enable_browser_archiving_triggering'] = 0;
            $general['browser_archiving_disabled_enforce'] = 1;
            $config->General = $general;
            Rules::setBrowserTriggerArchiving(false);
            Cache::getTransientCache()->flushAll();

            $callback();
        } finally {
            $general['enable_browser_archiving_triggering'] = $originalEnableBrowserArchivingTriggering;
            $general['browser_archiving_disabled_enforce'] = $originalBrowserArchivingDisabledEnforce;
            $config->General = $general;
            Rules::setBrowserTriggerArchiving((bool) $originalBrowserTriggerEnabled);
            Cache::getTransientCache()->flushAll();
        }
    }
}
