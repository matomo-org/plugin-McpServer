<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
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
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportProcessedTest extends IntegrationTestCase
{
    private const SUBTABLE_REPORT_REQUIRES_ID_SUBTABLE_MESSAGE =
        'Selected subtable report requires idSubtable. '
        . 'First query the parent report and use a returned row subtable ID.';
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
            'https://report-processed.test',
        );

        $tracker = Fixture::getTracker(
            $this->idSite,
            '2015-01-03 12:00:00',
            $defaultInit = true,
            $useLocal = true,
        );
        $tracker->setUrl('https://report-processed.test/page-a');
        Fixture::checkResponse($tracker->doTrackPageView('page-a'));
        $tracker->setUrl('https://report-processed.test/page-b');
        Fixture::checkResponse($tracker->doTrackPageView('page-b'));
        $tracker->setUrl('https://report-processed.test/page-c');
        Fixture::checkResponse($tracker->doTrackPageView('page-c'));

        $tracker->setForceNewVisit();
        $tracker->setUrlReferrer('https://somewebsite.com/');
        $tracker->setUrl('https://report-processed.test/referrer-a');
        Fixture::checkResponse($tracker->doTrackPageView('referrer-a'));

        $tracker->setForceNewVisit();
        $tracker->setUrlReferrer('http://somewebsite.com/');
        $tracker->setUrl('https://report-processed.test/referrer-b');
        Fixture::checkResponse($tracker->doTrackPageView('referrer-b'));
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
            false,
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
            __METHOD__,
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

    /**
     * The metadata lookup compares module and action exactly, so a padded pair resolves only
     * because convergence trims it.
     */
    public function testResolvesPaddedApiModuleAndApiActionPair(): void
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
                'apiModule' => ' Actions ',
                'apiAction' => "getPageUrls\n",
            ],
            __METHOD__,
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);
    }

    /**
     * The camelCase paging aliases reach Core as the paging the caller asked for, even when the
     * values arrive as strings: intake rewrites the key only, and the retyping is done by
     * `CompatibleCallToolHandler::coerceIntegerStringsForValidation()` and the SDK's
     * `ReferenceHandler` on dispatch.
     */
    public function testAppliesCamelCasePagingAliasesSuppliedAsStrings(): void
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
                'filterLimit' => '1',
                'filterOffset' => '1',
            ],
            __METHOD__,
        );

        $pagination = $content['pagination'] ?? null;
        self::assertIsArray($pagination);
        self::assertSame(1, $pagination['filter_limit'] ?? null);
        self::assertSame(1, $pagination['filter_offset'] ?? null);
        self::assertSame(1, $pagination['returned_rows'] ?? null);
        self::assertGreaterThanOrEqual(2, $pagination['total_rows'] ?? 0);
    }

    /**
     * A segment supplied inside apiParameters is lifted to the canonical top-level argument and
     * reaches Core, shown here by it filtering the report away.
     */
    public function testAppliesSegmentSuppliedInsideApiParameters(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $unsegmented = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
            ],
            __METHOD__,
        );
        self::assertIsArray($unsegmented['pagination'] ?? null);
        self::assertGreaterThanOrEqual(2, $unsegmented['pagination']['total_rows'] ?? 0);

        $segmented = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'apiParameters' => ['segment' => 'countryCode==de'],
            ],
            __METHOD__,
        );

        $pagination = $segmented['pagination'] ?? null;
        self::assertIsArray($pagination);
        self::assertSame(0, $pagination['total_rows'] ?? null);

        $report = $segmented['report'] ?? null;
        self::assertIsArray($report);
        self::assertSame([], $report['reportData'] ?? null);
    }

    /**
     * The four report controls the processed-report profile relocates are schema-invalid at the
     * top level, so they arrive in apiParameters only because intake moved them there. Surviving
     * the reportUniqueId path additionally requires ReportProcessedQueryService to still classify
     * all four as generic-safe: a report-specific leftover fails that lookup outright, and a key
     * dropped from the generic-safe set would never reach resolvedApiParameters. This pins the
     * profile's registration to that classification, which lives in another layer.
     */
    public function testRelocatedReportControlsStayGenericSafeApiParameters(): void
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
                'expanded' => true,
                'flat' => true,
                'filter_sort_column' => 'label',
                'filter_sort_order' => 'asc',
            ],
            __METHOD__,
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        $resolvedApiParameters = $resolvedReport['apiParameters'] ?? null;
        self::assertIsArray($resolvedApiParameters);
        self::assertTrue($resolvedApiParameters['expanded'] ?? null);
        self::assertTrue($resolvedApiParameters['flat'] ?? null);
        self::assertSame('label', $resolvedApiParameters['filter_sort_column'] ?? null);
        self::assertSame('asc', $resolvedApiParameters['filter_sort_order'] ?? null);

        // The relocated sort reached Core rather than only being echoed back: every tracked page
        // here has the same visit count, so the default ordering carries no label order to
        // mistake for this one. Comparing against the descending call keeps the assertion
        // independent of Matomo's string collation.
        $ascendingLabels = $this->readReportLabels($content);
        self::assertGreaterThanOrEqual(2, count($ascendingLabels));

        $descending = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'expanded' => true,
                'flat' => true,
                'filter_sort_column' => 'label',
                'filter_sort_order' => 'desc',
            ],
            __METHOD__,
        );

        self::assertSame(array_reverse($ascendingLabels), $this->readReportLabels($descending));
    }

    public function testCoercesIntegerStringArgumentsThroughRealToolStack(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        // Clients (notably smaller LLMs) routinely stringify numeric arguments.
        // Passing idSite/filter_limit/filter_offset as strings must still clear
        // the strict integer schema and execute, yielding integer pagination.
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => (string) $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'filter_limit' => '1',
                'filter_offset' => '0',
            ],
            __METHOD__,
        );

        $pagination = $content['pagination'] ?? null;
        self::assertIsArray($pagination);
        self::assertSame(1, $pagination['filter_limit'] ?? null);
        self::assertSame(0, $pagination['filter_offset'] ?? null);
        self::assertSame(1, $pagination['returned_rows'] ?? null);
        self::assertGreaterThanOrEqual(2, $pagination['total_rows'] ?? 0);
    }

    public function testExpandsWholePeriodShorthandDateThroughRealToolStack(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        // period=year + bare "2015" must expand to 2015-01-01 and resolve the
        // year bucket containing the tracked 2015-01-03 hits. Without expansion
        // the value would reach strtotime() and silently resolve elsewhere,
        // returning an empty report.
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'year',
                'date' => '2015',
                'reportUniqueId' => $reportUniqueId,
            ],
            __METHOD__,
        );

        $pagination = $content['pagination'] ?? null;
        self::assertIsArray($pagination);
        self::assertGreaterThanOrEqual(2, $pagination['total_rows'] ?? 0);
    }

    public function testResolvesReportByDottedApiMethodSelectorThroughRealToolStack(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        // A client that sends the API-method form "Actions.getPageUrls" instead
        // of the "Actions_getPageUrls" uniqueId must resolve the same report.
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => 'Actions.getPageUrls',
            ],
            __METHOD__,
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);
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
            __METHOD__,
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
            __METHOD__,
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
            __METHOD__,
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

    public function testAcceptsDotFormReportUniqueId(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertSame('Actions_getPageUrls', $reportUniqueId);

        $result = $this->callReportProcessed(['reportUniqueId' => 'Actions.getPageUrls']);

        $resolvedReport = $result['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        self::assertSame('Actions_getPageUrls', $resolvedReport['uniqueId'] ?? null);
    }

    public function testRejectsCombinedUniqueIdAndModuleActionAtSchemaLevel(): void
    {
        $this->assertInvalidSelectorArgumentsAtSchemaLevel([
            'reportUniqueId' => 'VisitsSummary_get',
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
        ]);
    }

    public function testDoesNotFuzzyMatchAnUnknownDotFormReport(): void
    {
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
                'reportUniqueId' => 'Pages.getPageTitles',
            ],
            'Report not found.',
            __METHOD__,
        );
    }

    /**
     * @param array<string, mixed> $selectorArguments
     *
     * @return array<string, mixed>
     */
    private function callReportProcessed(array $selectorArguments): array
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        return McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            array_merge(
                [
                    'idSite' => $this->idSite,
                    'period' => 'day',
                    'date' => '2015-01-03',
                ],
                $selectorArguments,
            ),
            __METHOD__,
        );
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
            __METHOD__,
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
            __METHOD__,
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);

        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);
    }

    public function testRejectsSubtableReportByUniqueIdWithoutIdSubtable(): void
    {
        $report = $this->findSpecificReportMetadata($this->idSite, 'Referrers', 'getUrlsFromWebsiteId', true);
        self::assertNotNull($report);

        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

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
                'reportUniqueId' => $uniqueId,
            ],
            self::SUBTABLE_REPORT_REQUIRES_ID_SUBTABLE_MESSAGE,
            __METHOD__,
        );
    }

    public function testRejectsSubtableReportByModuleActionAndParametersWithoutIdSubtable(): void
    {
        $report = $this->findSpecificReportMetadata($this->idSite, 'Referrers', 'getUrlsFromWebsiteId', true);
        self::assertNotNull($report);

        $module = $report['module'] ?? null;
        $action = $report['action'] ?? null;
        $parameters = $report['parameters'] ?? [];
        self::assertIsString($module);
        self::assertIsString($action);
        self::assertIsArray($parameters);

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
                'apiModule' => $module,
                'apiAction' => $action,
                'apiParameters' => $parameters,
            ],
            self::SUBTABLE_REPORT_REQUIRES_ID_SUBTABLE_MESSAGE,
            __METHOD__,
        );
    }

    public function testReturnsProcessedSubtableReportWhenIdSubtableIsProvided(): void
    {
        $subtableReport = $this->findSpecificReportMetadata($this->idSite, 'Referrers', 'getUrlsFromWebsiteId', true);
        self::assertNotNull($subtableReport);
        $parentReport = $this->findParentReportForSubtable($this->idSite, $subtableReport);
        self::assertNotNull($parentReport);

        $parentUniqueId = $parentReport['uniqueId'] ?? null;
        $subtableUniqueId = $subtableReport['uniqueId'] ?? null;
        self::assertIsString($parentUniqueId);
        self::assertIsString($subtableUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $parentContent = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $parentUniqueId,
            ],
            __METHOD__ . '#parent',
        );

        $idSubtable = $this->extractFirstIdSubtableFromProcessedContent($parentContent);
        self::assertNotNull($idSubtable);

        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $subtableUniqueId,
                'idSubtable' => $idSubtable,
            ],
            __METHOD__ . '#subtable',
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        self::assertSame($subtableUniqueId, $resolvedReport['uniqueId'] ?? null);
        self::assertIsArray($content['report'] ?? null);
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
                __METHOD__,
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
            __METHOD__,
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
            __METHOD__,
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
            __METHOD__,
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
            'exact',
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
            __METHOD__,
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

        $notSchema = $inputSchema['not'];
        self::assertArrayHasKey('anyOf', $notSchema);
        self::assertIsArray($notSchema['anyOf']);

        $properties = $inputSchema['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('goalMetricsMode', $properties);
        self::assertArrayHasKey('goalMetricsProcessGoals', $properties);
        $apiParameters = $properties['apiParameters'] ?? null;
        self::assertIsArray($apiParameters);
        self::assertSame('object', $apiParameters['type'] ?? null);
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
            __METHOD__,
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
            __METHOD__,
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
            McpTestHelper::parseCallTool(McpTestHelper::decodeResponse($response)),
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
            __METHOD__,
        );

        $resolvedReport = $content['resolvedReport'] ?? null;
        self::assertIsArray($resolvedReport);
        self::assertSame($reportUniqueId, $resolvedReport['uniqueId'] ?? null);
    }

    public function testRejectsModuleActionWithNonEmptyListApiParametersAtSchemaLevel(): void
    {
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
                'apiModule' => 'Actions',
                'apiAction' => 'getPageUrls',
                'apiParameters' => ['flat'],
            ],
            __METHOD__,
        );

        self::assertStringContainsString('Property \'/apiParameters\'', $error->message);
    }

    public function testRejectsStringApiParametersAtSchemaLevel(): void
    {
        $this->assertInvalidSelectorPayloadAtSchemaLevel(json_encode([
            'jsonrpc' => '2.0',
            'id' => __METHOD__,
            'method' => 'tools/call',
            'params' => [
                'name' => ReportProcessed::TOOL_NAME,
                'arguments' => [
                    'idSite' => $this->idSite,
                    'period' => 'day',
                    'date' => '2015-01-03',
                    'apiModule' => 'Actions',
                    'apiAction' => 'getPageUrls',
                    'apiParameters' => 'bad',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testRejectsNumericApiParametersAtSchemaLevel(): void
    {
        $this->assertInvalidSelectorPayloadAtSchemaLevel(json_encode([
            'jsonrpc' => '2.0',
            'id' => __METHOD__,
            'method' => 'tools/call',
            'params' => [
                'name' => ReportProcessed::TOOL_NAME,
                'arguments' => [
                    'idSite' => $this->idSite,
                    'period' => 'day',
                    'date' => '2015-01-03',
                    'apiModule' => 'Actions',
                    'apiAction' => 'getPageUrls',
                    'apiParameters' => 123,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
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
                __METHOD__,
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
                true,
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
                __METHOD__,
            );

            self::assertArrayHasKey('report', $content);
            self::assertArrayHasKey('pagination', $content);
            self::assertArrayHasKey('resolvedReport', $content);
            $pagination = $content['pagination'] ?? null;
            self::assertIsArray($pagination);
            self::assertArrayHasKey('total_rows', $pagination);
        });
    }

    /**
     * An unknown segment field names the problem, rather than reporting the whole retrieval as
     * failed. The archiving mode does not change that: the field is unavailable either way.
     */
    public function testNamesTheProblemForUnknownSegmentFieldInStrictArchivingMode(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $this->runInStrictSegmentArchivingMode(function () use ($reportUniqueId): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);

            $result = McpTestHelper::callToolAndAssertError(
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
                null,
                __METHOD__,
            );

            $content = $result->content[0] ?? null;
            self::assertInstanceOf(TextContent::class, $content);
            $text = $content->text;
            self::assertIsString($text);
            self::assertStringContainsString('names a field this Matomo does not provide', $text);
        });
    }

    /**
     * A segment whose operator does not exist is reported as an expression the caller can fix,
     * naming the accepted operators. This is the shape a lifted `apiParameters.segment` reaches
     * Core as, so the lift must not be the only thing standing between a typo and an opaque
     * `Report retrieval failed.`
     */
    public function testNamesTheProblemForMalformedSegmentOperator(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $result = McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'segment' => 'pageUrl=~pricing',
            ],
            null,
            __METHOD__,
        );

        $content = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $content);
        $text = $content->text;
        self::assertIsString($text);
        self::assertStringContainsString('Segment expression could not be parsed.', $text);
        // Naming the accepted operators is what makes the message actionable.
        self::assertStringContainsString('=@', $text);
        // The expression may carry personal data, so guidance states the form without repeating it.
        self::assertStringNotContainsString('pageUrl=~pricing', $text);
    }

    /**
     * The same guidance is reached when the segment arrives nested and is lifted at intake.
     */
    public function testNamesTheProblemForMalformedSegmentLiftedFromApiParameters(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $result = McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'period' => 'day',
                'date' => '2015-01-03',
                'reportUniqueId' => $reportUniqueId,
                'apiParameters' => ['segment' => 'pageUrl=~pricing'],
            ],
            null,
            __METHOD__,
        );

        $content = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $content);
        $text = $content->text;
        self::assertIsString($text);
        self::assertStringContainsString('Segment expression could not be parsed.', $text);
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
                    ?int $idSubtable,
                ): array {
                    throw new CoreApiRequestException(
                        'Core API processed report request failed.',
                        0,
                        new \RuntimeException('report data has not been pre-processed'),
                    );
                }
            },
        );
        $container->set(
            StrictSegmentPolicyServiceInterface::class,
            new class () implements StrictSegmentPolicyServiceInterface {
                public function shouldMapToStrictSegmentGuidance(
                    int $idSite,
                    string $period,
                    string $date,
                    ?string $segment,
                ): bool {
                    return true;
                }
            },
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
                    __METHOD__,
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
                    ?int $idSubtable,
                ): array {
                    $reportData = new DataTable();
                    $reportData->setMetadata(DataTable::TOTAL_ROWS_BEFORE_LIMIT_METADATA_NAME, 0);

                    return [
                        'reportData' => $reportData,
                        'reportMetadata' => new DataTable(),
                        'columns' => ['label' => 'Label'],
                    ];
                }
            },
        );
        $container->set(
            StrictSegmentPolicyServiceInterface::class,
            new class () implements StrictSegmentPolicyServiceInterface {
                public function shouldMapToStrictSegmentGuidance(
                    int $idSite,
                    string $period,
                    string $date,
                    ?string $segment,
                ): bool {
                    return true;
                }
            },
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
                    __METHOD__,
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
                    ?int $idSubtable,
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
            },
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
                    __METHOD__,
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
                    ?int $idSubtable,
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
            },
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
                    __METHOD__,
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
            $selectorArguments,
        );

        $error = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            ReportProcessed::TOOL_NAME,
            $arguments,
            __METHOD__,
        );

        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportProcessed::TOOL_NAME . "':",
            $error->message ?? '',
        );
    }

    private function assertInvalidSelectorPayloadAtSchemaLevel(string $payload): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);
        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportProcessed::TOOL_NAME . "':",
            $message->message ?? '',
        );
    }

    /**
     * @return array{module: string, action: string, uniqueId: string}|null
     */
    private function findGoalParameterizedReport(int $idSite, int $idGoal): ?array
    {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);

        foreach ($metadata as $row) {
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
     * @return array<string, mixed>|null
     */
    /**
     * @return array<string, mixed>|null
     */
    private function findSpecificReportMetadata(
        int $idSite,
        string $module,
        string $action,
        bool $includeSubtableReports,
    ): ?array {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata(
            (string) $idSite,
            false,
            false,
            $includeSubtableReports,
            $includeSubtableReports,
        );

        foreach ($metadata as $report) {
            if (($report['module'] ?? null) !== $module || ($report['action'] ?? null) !== $action) {
                continue;
            }

            return $report;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $subtableReport
     * @return array<string, mixed>|null
     */
    private function findParentReportForSubtable(int $idSite, array $subtableReport): ?array
    {
        $module = $subtableReport['module'] ?? null;
        $action = $subtableReport['action'] ?? null;
        if (!is_string($module) || !is_string($action)) {
            return null;
        }

        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);

        foreach ($metadata as $report) {
            if ($this->isSubtableMetadataRow($report)) {
                continue;
            }

            if (($report['module'] ?? null) !== $module) {
                continue;
            }

            if (($report['actionToLoadSubTables'] ?? null) !== $action) {
                continue;
            }

            return $report;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return list<string>
     */
    private function readReportLabels(array $content): array
    {
        $report = $content['report'] ?? null;
        self::assertIsArray($report);
        $reportData = $report['reportData'] ?? null;
        self::assertIsArray($reportData);

        $labels = [];
        foreach ($reportData as $row) {
            self::assertIsArray($row);
            $label = $row['label'] ?? null;
            self::assertIsString($label);

            $labels[] = $label;
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function extractFirstIdSubtableFromProcessedContent(array $content): ?int
    {
        $report = $content['report'] ?? null;
        if (!is_array($report)) {
            return null;
        }

        $reportMetadata = $report['reportMetadata'] ?? null;
        if (!is_array($reportMetadata)) {
            return null;
        }

        foreach ($reportMetadata as $row) {
            if (!is_array($row)) {
                continue;
            }

            $idSubtable = $row['idsubdatatable'] ?? null;
            if (is_int($idSubtable) && $idSubtable > 0) {
                return $idSubtable;
            }

            if (is_string($idSubtable) && ctype_digit($idSubtable) && (int) $idSubtable > 0) {
                return (int) $idSubtable;
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
