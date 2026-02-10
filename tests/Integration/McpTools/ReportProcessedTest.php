<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Piwik\Access;
use Piwik\DataTable;
use Piwik\NoAccessException;
use Piwik\Plugins\API\API as ApiModuleApi;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportProcessedTest extends IntegrationTestCase
{
    private int $idSite;

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

    public function testMasksNoAccessAsNotFound(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        McpAuthTestHelper::asNoAccessUser(function () use ($reportUniqueId): void {
            try {
                Access::getInstance()->checkUserHasViewAccess($this->idSite);
                $this->markTestSkipped(
                    'No-access fixture has view access in this runtime; '
                    . 'cannot deterministically assert masked no-access error for processed reports.'
                );
            } catch (NoAccessException $e) {
                // expected: this fixture should not have view access.
            }

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

    private function findReportUniqueId(int $idSite, string $module, string $action): ?string
    {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);

        foreach ($metadata as $row) {
            if (!is_array($row) || $this->isSubtableMetadataRow($row)) {
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
     * @return array{module: string, action: string, uniqueId: string}|null
     */
    private function findGoalParameterizedReport(int $idSite, int $idGoal): ?array
    {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);

        foreach ($metadata as $row) {
            if (!is_array($row) || $this->isSubtableMetadataRow($row)) {
                continue;
            }

            $parameters = $row['parameters'] ?? null;
            if (!is_array($parameters)) {
                continue;
            }

            $rowIdGoal = $parameters['idGoal'] ?? null;
            if ((string) $rowIdGoal !== (string) $idGoal) {
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
     * @param array<string, mixed> $row
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
}
