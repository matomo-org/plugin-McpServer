<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Matomo\Dependencies\McpServer\Mcp\Server;
use Piwik\Config;
use Piwik\Plugins\API\API as ApiModuleApi;
use Piwik\Plugins\CustomDimensions\API as CustomDimensionsApi;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\McpTools\ApiCall;
use Piwik\Plugins\McpServer\McpTools\ApiGet;
use Piwik\Plugins\McpServer\McpTools\DimensionGet;
use Piwik\Plugins\McpServer\McpTools\DimensionList;
use Piwik\Plugins\McpServer\McpTools\GoalGet;
use Piwik\Plugins\McpServer\McpTools\GoalList;
use Piwik\Plugins\McpServer\McpTools\ReportList;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\McpTools\SegmentGet;
use Piwik\Plugins\McpServer\McpTools\SegmentList;
use Piwik\Plugins\McpServer\McpTools\SiteGet;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\McpTools\SiteSearch;
use Piwik\Plugins\McpServer\Schemas\Api\ApiCallToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Api\ApiMethodSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Dimensions\DimensionDetailToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Dimensions\DimensionSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Goals\GoalDetailToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Goals\GoalSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportMetadataToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportProcessedToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Segments\SegmentDetailToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Segments\SegmentSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Sites\SiteDetailToolOutputSchema;
use Piwik\Plugins\McpServer\Schemas\Sites\SiteSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\tests\Framework\ContractShapeAssert;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpToolsContractBaselineTest extends IntegrationTestCase
{
    private int $idSite = 0;
    private int $idSegment = 0;
    private int $idDimension = 0;
    private int $idGoal = 0;
    private string $reportUniqueId = '';

    protected static function configureFixture($fixture): void
    {
        parent::configureFixture($fixture);

        $fixture->createSuperUser = true;
    }

    public function setUp(): void
    {
        parent::setUp();

        $suffix = substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8);
        $this->idSite = Fixture::createWebsite(
            '2015-01-01 00:00:00',
            0,
            'MCP Contract Baseline Site ' . $suffix,
            'https://baseline-main.test',
        );
        Fixture::createWebsite(
            '2015-01-01 00:00:00',
            0,
            'MCP Contract Searchable Site ' . $suffix,
            'https://baseline-search.test',
        );

        $this->idSegment = SegmentEditorApi::getInstance()->add(
            'MCP Contract Segment ' . $suffix,
            'countryCode==de',
            $this->idSite,
        );

        $this->idDimension = (int) CustomDimensionsApi::getInstance()->configureNewCustomDimension(
            $this->idSite,
            'MCP Contract Dimension ' . $suffix,
            'action',
            1,
        );

        $this->idGoal = (int) GoalsApi::getInstance()->addGoal(
            $this->idSite,
            'MCP Contract Goal ' . $suffix,
            'event_action',
            'evt-contract-goal',
            'exact',
            false,
            false,
            true,
            'MCP Contract Goal Description',
            true,
        );

        $tracker = Fixture::getTracker(
            $this->idSite,
            '2015-01-03 12:00:00',
            $defaultInit = true,
            $useLocal = true,
        );
        $tracker->setUrl('https://baseline-main.test/page-a');
        Fixture::checkResponse($tracker->doTrackPageView('page-a'));
        $tracker->setUrl('https://baseline-main.test/page-b');
        Fixture::checkResponse($tracker->doTrackPageView('page-b'));

        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId, 'Expected Actions/getPageUrls report in metadata.');
        $this->reportUniqueId = $reportUniqueId;
    }

    /**
     * @dataProvider provideSuccessCases
     *
     * @param array<string, mixed> $expectedSchema
     */
    public function testToolSuccessShape(
        string $toolName,
        string $successArgumentsMethod,
        array $expectedSchema,
    ): void {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        /** @var array<string, mixed> $arguments */
        $arguments = $this->{$successArgumentsMethod}();
        $content = McpTestHelper::callToolAndAssertSuccess($server, $sessionId, $toolName, $arguments, __METHOD__);

        ContractShapeAssert::assertMatchesSchema($expectedSchema, $content);
    }

    /**
     * @dataProvider provideErrorCases
     */
    public function testToolCoreErrorScenarios(string $errorScenarioMethod): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $this->{$errorScenarioMethod}($server, $sessionId);
    }

    public function testReportProcessedSerializesEmptyResolvedApiParametersAsObjectInBaselineResponse(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ReportProcessed::TOOL_NAME,
            $this->reportProcessedSuccessArguments(),
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $body = McpTestHelper::getResponseBody($response);

        self::assertStringContainsString('"resolvedReport"', $body);
        self::assertStringContainsString('"apiParameters":{}', $body);
    }

    public function testReportMetadataSerializesEmptyParametersAsObjectInBaselineResponse(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'apiModule' => 'Actions',
                'apiAction' => 'getPageUrls',
                'apiParameters' => new \stdClass(),
            ],
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $body = McpTestHelper::getResponseBody($response);

        self::assertStringContainsString('"parameters":{}', $body);
        self::assertStringContainsString('"uniqueId":"' . $this->reportUniqueId . '"', $body);
    }

    public function testReportMetadataAcceptsEmptyObjectApiParametersInBaselineResponse(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'id' => __METHOD__,
            'method' => 'tools/call',
            'params' => [
                'name' => ReportMetadata::TOOL_NAME,
                'arguments' => [
                    'idSite' => $this->idSite,
                    'reportUniqueId' => $this->reportUniqueId,
                    'apiParameters' => new \stdClass(),
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $body = McpTestHelper::getResponseBody($response);

        self::assertStringContainsString('"parameters":{}', $body);
        self::assertStringContainsString('"uniqueId":"' . $this->reportUniqueId . '"', $body);
    }

    public function testReportListSerializesEmptyParametersAsObjectInBaselineResponse(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ReportList::TOOL_NAME,
            $this->reportListSuccessArguments(),
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $body = McpTestHelper::getResponseBody($response);

        self::assertStringContainsString('"reports"', $body);
        self::assertStringContainsString('"parameters":{}', $body);
    }

    public function testApiListSuccessShapeInReadMode(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['limit' => 5],
            __METHOD__,
        );

        ContractShapeAssert::assertMatchesSchema(ApiMethodSummaryToolOutputSchema::PAGINATED_LIST, $content);
        self::assertNotEmpty($content['methods'] ?? []);
    }

    public function testApiGetSuccessShapeInReadMode(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['method' => 'API.getMatomoVersion'],
            __METHOD__,
        );

        ContractShapeAssert::assertMatchesSchema(ApiMethodSummaryToolOutputSchema::ITEM, $content);
    }

    public function testApiCallSuccessShapeInReadMode(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ApiCall::TOOL_NAME,
            ['method' => 'API.getMatomoVersion'],
            __METHOD__,
        );

        ContractShapeAssert::assertMatchesSchema(ApiCallToolOutputSchema::ITEM, $content);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function provideSuccessCases(): array
    {
        return [
            'site_get' => [SiteGet::TOOL_NAME, 'siteGetSuccessArguments', SiteDetailToolOutputSchema::ITEM],
            'site_list' => [
                SiteList::TOOL_NAME,
                'siteListSuccessArguments',
                SiteSummaryToolOutputSchema::PAGINATED_LIST,
            ],
            'site_search' => [
                SiteSearch::TOOL_NAME,
                'siteSearchSuccessArguments',
                SiteSummaryToolOutputSchema::PAGINATED_LIST,
            ],
            'segment_get' => [SegmentGet::TOOL_NAME, 'segmentGetSuccessArguments', SegmentDetailToolOutputSchema::ITEM],
            'segment_list' => [
                SegmentList::TOOL_NAME,
                'segmentListSuccessArguments',
                SegmentSummaryToolOutputSchema::PAGINATED_LIST,
            ],
            'dimension_get' => [
                DimensionGet::TOOL_NAME,
                'dimensionGetSuccessArguments',
                DimensionDetailToolOutputSchema::ITEM,
            ],
            'dimension_list' => [
                DimensionList::TOOL_NAME,
                'dimensionListSuccessArguments',
                DimensionSummaryToolOutputSchema::PAGINATED_LIST,
            ],
            'goal_get' => [GoalGet::TOOL_NAME, 'goalGetSuccessArguments', GoalDetailToolOutputSchema::ITEM],
            'goal_list' => [
                GoalList::TOOL_NAME,
                'goalListSuccessArguments',
                GoalSummaryToolOutputSchema::PAGINATED_LIST,
            ],
            'report_list' => [
                ReportList::TOOL_NAME,
                'reportListSuccessArguments',
                ReportSummaryToolOutputSchema::PAGINATED_LIST,
            ],
            'report_metadata' => [
                ReportMetadata::TOOL_NAME,
                'reportMetadataSuccessArguments',
                ReportMetadataToolOutputSchema::ITEM,
            ],
            'report_processed' => [
                ReportProcessed::TOOL_NAME,
                'reportProcessedSuccessArguments',
                ReportProcessedToolOutputSchema::ITEM,
            ],
        ];
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideErrorCases(): array
    {
        return [
            'site_get_not_found' => ['assertSiteGetNotFound'],
            'site_list_invalid_cursor' => ['assertSiteListInvalidCursor'],
            'site_search_invalid_cursor' => ['assertSiteSearchInvalidCursor'],
            'segment_get_not_found' => ['assertSegmentGetNotFound'],
            'segment_list_invalid_cursor' => ['assertSegmentListInvalidCursor'],
            'dimension_get_not_found' => ['assertDimensionGetNotFound'],
            'dimension_list_invalid_cursor' => ['assertDimensionListInvalidCursor'],
            'goal_get_not_found' => ['assertGoalGetNotFound'],
            'goal_list_invalid_cursor' => ['assertGoalListInvalidCursor'],
            'report_list_invalid_cursor' => ['assertReportListInvalidCursor'],
            'report_metadata_not_found' => ['assertReportMetadataNotFound'],
            'report_processed_rejects_dangerous_api_parameter' => ['assertReportProcessedRejectsDangerousApiParameter'],
        ];
    }

    /** @return array<string, mixed> */
    private function siteGetSuccessArguments(): array
    {
        return ['idSite' => $this->idSite];
    }

    /** @return array<string, mixed> */
    private function siteListSuccessArguments(): array
    {
        return ['limit' => 5];
    }

    /** @return array<string, mixed> */
    private function siteSearchSuccessArguments(): array
    {
        return ['search' => 'Contract Searchable', 'limit' => 5];
    }

    /** @return array<string, mixed> */
    private function segmentGetSuccessArguments(): array
    {
        return ['idSite' => $this->idSite, 'idSegment' => $this->idSegment];
    }

    /** @return array<string, mixed> */
    private function segmentListSuccessArguments(): array
    {
        return ['idSite' => $this->idSite, 'limit' => 5];
    }

    /** @return array<string, mixed> */
    private function dimensionGetSuccessArguments(): array
    {
        return ['idSite' => $this->idSite, 'idDimension' => $this->idDimension];
    }

    /** @return array<string, mixed> */
    private function dimensionListSuccessArguments(): array
    {
        return ['idSite' => $this->idSite, 'limit' => 5];
    }

    /** @return array<string, mixed> */
    private function goalGetSuccessArguments(): array
    {
        return ['idSite' => $this->idSite, 'idGoal' => $this->idGoal];
    }

    /** @return array<string, mixed> */
    private function goalListSuccessArguments(): array
    {
        return ['idSite' => $this->idSite, 'limit' => 5];
    }

    /** @return array<string, mixed> */
    private function reportListSuccessArguments(): array
    {
        return ['idSite' => $this->idSite, 'limit' => 5];
    }

    /** @return array<string, mixed> */
    private function reportMetadataSuccessArguments(): array
    {
        return ['idSite' => $this->idSite, 'reportUniqueId' => $this->reportUniqueId];
    }

    /** @return array<string, mixed> */
    private function reportProcessedSuccessArguments(): array
    {
        return [
            'idSite' => $this->idSite,
            'period' => 'day',
            'date' => '2015-01-03',
            'reportUniqueId' => $this->reportUniqueId,
            'filter_limit' => 2,
            'filter_offset' => 0,
        ];
    }

    private function assertSiteGetNotFound(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SiteGet::TOOL_NAME,
            ['idSite' => 999999],
            'Site not found or access denied.',
            __METHOD__,
        );
    }

    private function assertSiteListInvalidCursor(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['cursor' => 'invalid'],
            'Invalid cursor.',
            __METHOD__,
        );
    }

    private function assertSiteSearchInvalidCursor(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SiteSearch::TOOL_NAME,
            ['search' => 'Contract Searchable', 'cursor' => 'invalid'],
            'Invalid cursor.',
            __METHOD__,
        );
    }

    private function assertSegmentGetNotFound(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SegmentGet::TOOL_NAME,
            ['idSite' => $this->idSite, 'idSegment' => 999999],
            'Segment not found.',
            __METHOD__,
        );
    }

    private function assertSegmentListInvalidCursor(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => 'invalid'],
            'Invalid cursor.',
            __METHOD__,
        );
    }

    private function assertDimensionGetNotFound(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            DimensionGet::TOOL_NAME,
            ['idSite' => $this->idSite, 'idDimension' => 999999],
            'Dimension not found.',
            __METHOD__,
        );
    }

    private function assertDimensionListInvalidCursor(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => 'invalid'],
            'Invalid cursor.',
            __METHOD__,
        );
    }

    private function assertGoalGetNotFound(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            GoalGet::TOOL_NAME,
            ['idSite' => $this->idSite, 'idGoal' => 999999],
            'Goal not found.',
            __METHOD__,
        );
    }

    private function assertGoalListInvalidCursor(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => 'invalid'],
            'Invalid cursor.',
            __METHOD__,
        );
    }

    private function assertReportListInvalidCursor(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => 'invalid'],
            'Invalid cursor.',
            __METHOD__,
        );
    }

    private function assertReportMetadataNotFound(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            ['idSite' => $this->idSite, 'reportUniqueId' => 'not-a-real-report-id'],
            'Report not found.',
            __METHOD__,
        );
    }

    private function assertReportProcessedRejectsDangerousApiParameter(Server $server, string $sessionId): void
    {
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $this->reportUniqueId,
                'apiParameters' => ['method' => 'VisitsSummary.get'],
            ],
            "Unsupported apiParameters key 'method'.",
            __METHOD__,
        );
    }

    private function findReportUniqueId(int $idSite, string $module, string $action): ?string
    {
        $reports = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);
        foreach ($reports as $report) {
            if (($report['module'] ?? null) !== $module || ($report['action'] ?? null) !== $action) {
                continue;
            }

            $uniqueId = $report['uniqueId'] ?? null;
            if (is_string($uniqueId) && $uniqueId !== '') {
                return $uniqueId;
            }
        }

        return null;
    }
}
