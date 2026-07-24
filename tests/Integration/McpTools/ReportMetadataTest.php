<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Piwik\Container\StaticContainer;
use Piwik\Plugins\API\API as ApiModuleApi;
use Piwik\Plugins\API\ProcessedReport;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Translation\Translator;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportMetadataTest extends IntegrationTestCase
{
    private int $idSite = 0;

    public function setUp(): void
    {
        parent::setUp();
        Fixture::loadAllTranslations();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Report Metadata Test Site',
            'https://report-metadata.test',
        );
    }

    public function testReturnsReportByUniqueId(): void
    {
        $report = $this->findAnyReportMetadata($this->idSite);
        self::assertNotNull($report);

        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            ['idSite' => $this->idSite, 'reportUniqueId' => $uniqueId],
            __METHOD__,
        );

        self::assertSame($uniqueId, $content['uniqueId'] ?? null);
        self::assertArrayHasKey('metadata', $content);
        self::assertIsArray($content['metadata']);
        self::assertArrayHasKey('parameters', $content);
        self::assertIsArray($content['parameters']);
        self::assertArrayHasKey('isSubtableReport', $content);
        self::assertArrayHasKey('actionToLoadSubTables', $content);
    }

    public function testReturnsReportByModuleActionAndParameters(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'apiModule' => 'Actions',
                'apiAction' => 'getPageUrls',
            ],
            __METHOD__,
        );

        self::assertSame($reportUniqueId, $content['uniqueId'] ?? null);
    }

    public function testReturnsSubtableReportByUniqueId(): void
    {
        $report = $this->findAnySubtableReportMetadata($this->idSite);
        self::assertNotNull($report);

        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            ['idSite' => $this->idSite, 'reportUniqueId' => $uniqueId],
            __METHOD__,
        );

        self::assertSame($uniqueId, $content['uniqueId'] ?? null);
        self::assertTrue($content['isSubtableReport'] ?? false);
    }

    public function testReturnsSubtableReportByModuleActionAndParameters(): void
    {
        $report = $this->findAnySubtableReportMetadata($this->idSite);
        self::assertNotNull($report);

        $module = $report['module'] ?? null;
        $action = $report['action'] ?? null;
        $parameters = $report['parameters'] ?? [];
        self::assertIsString($module);
        self::assertIsString($action);
        self::assertIsArray($parameters);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'apiModule' => $module,
                'apiAction' => $action,
                'apiParameters' => $parameters,
            ],
            __METHOD__,
        );

        self::assertSame($report['uniqueId'] ?? null, $content['uniqueId'] ?? null);
        self::assertTrue($content['isSubtableReport'] ?? false);
    }

    public function testSerializesEmptyParametersAsObjectInResponseBody(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

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
        self::assertStringContainsString('"uniqueId":"' . $reportUniqueId . '"', $body);
    }

    public function testReturnsReportByModuleActionWithMonthLast3(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'apiModule' => 'Actions',
                'apiAction' => 'getPageUrls',
                'period' => 'month',
                'date' => 'last3',
            ],
            __METHOD__,
        );

        self::assertSame($reportUniqueId, $content['uniqueId'] ?? null);
    }

    public function testReturnsReportByModuleActionWithExplicitRangeDate(): void
    {
        $reportUniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertNotNull($reportUniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'apiModule' => 'Actions',
                'apiAction' => 'getPageUrls',
                'period' => 'range',
                'date' => '2015-01-01,2015-01-31',
            ],
            __METHOD__,
        );

        self::assertSame($reportUniqueId, $content['uniqueId'] ?? null);
    }

    public function testAllowsUniqueIdWithPeriodAndDate(): void
    {
        $report = $this->findAnyReportMetadata($this->idSite);
        self::assertNotNull($report);
        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'reportUniqueId' => $uniqueId,
                'period' => 'week',
                'date' => 'today',
            ],
            __METHOD__,
        );

        self::assertSame($uniqueId, $content['uniqueId'] ?? null);
    }

    public function testRejectsCombinedUniqueIdAndApiModuleAtSchemaLevel(): void
    {
        $uniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertSame('Actions_getPageUrls', $uniqueId);

        $this->assertInvalidSchemaArguments([
            'idSite' => $this->idSite,
            'reportUniqueId' => $uniqueId,
            'apiModule' => 'Actions',
        ]);
    }

    public function testAcceptsDotFormReportUniqueId(): void
    {
        $uniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertSame('Actions_getPageUrls', $uniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            ['idSite' => $this->idSite, 'reportUniqueId' => 'Actions.getPageUrls'],
            __METHOD__,
        );

        self::assertSame('Actions_getPageUrls', $content['uniqueId'] ?? null);
    }

    /**
     * The metadata lookup compares module and action exactly, so a padded pair resolves only
     * because convergence trims it.
     */
    public function testResolvesPaddedApiModuleAndApiActionPair(): void
    {
        $uniqueId = $this->findReportUniqueId($this->idSite, 'Actions', 'getPageUrls');
        self::assertSame('Actions_getPageUrls', $uniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'apiModule' => ' Actions ',
                'apiAction' => "getPageUrls\n",
            ],
            __METHOD__,
        );

        self::assertSame($uniqueId, $content['uniqueId'] ?? null);
    }

    public function testRejectsCombinedUniqueIdAndModuleActionAtSchemaLevel(): void
    {
        $this->assertInvalidSchemaArguments([
            'idSite' => $this->idSite,
            'reportUniqueId' => 'VisitsSummary_get',
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
        ]);
    }

    public function testRejectsMissingSelectorAtSchemaLevel(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ReportMetadata::TOOL_NAME,
            ['idSite' => $this->idSite],
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportMetadata::TOOL_NAME . "':",
            $message->message,
        );
    }

    public function testRejectsApiModuleWithoutApiActionAtSchemaLevel(): void
    {
        $this->assertInvalidSchemaArguments([
            'idSite' => $this->idSite,
            'apiModule' => 'Actions',
        ]);
    }

    public function testRejectsApiActionWithoutApiModuleAtSchemaLevel(): void
    {
        $this->assertInvalidSchemaArguments([
            'idSite' => $this->idSite,
            'apiAction' => 'getPageUrls',
        ]);
    }

    public function testRejectsCombinedUniqueIdAndApiParametersAtSchemaLevel(): void
    {
        $report = $this->findAnyReportMetadata($this->idSite);
        self::assertNotNull($report);
        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

        $this->assertInvalidSchemaArguments([
            'idSite' => $this->idSite,
            'reportUniqueId' => $uniqueId,
            'apiParameters' => ['idGoal' => 1],
        ]);
    }

    public function testAllowsUniqueIdCombinedWithEmptyListApiParameters(): void
    {
        $report = $this->findAnyReportMetadata($this->idSite);
        self::assertNotNull($report);
        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'reportUniqueId' => $uniqueId,
                'apiParameters' => [],
            ],
            __METHOD__,
        );

        self::assertSame($uniqueId, $content['uniqueId'] ?? null);
    }

    public function testAllowsUniqueIdCombinedWithEmptyObjectApiParameters(): void
    {
        $report = $this->findAnyReportMetadata($this->idSite);
        self::assertNotNull($report);
        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

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
                    'reportUniqueId' => $uniqueId,
                    'apiParameters' => new \stdClass(),
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        self::assertSame($uniqueId, $result->structuredContent['uniqueId'] ?? null);
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
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'apiModule' => 'Actions',
                'apiAction' => 'getPageUrls',
                'apiParameters' => [],
            ],
            __METHOD__,
        );

        self::assertSame($reportUniqueId, $content['uniqueId'] ?? null);
    }

    public function testAllowsModuleActionCombinedWithEmptyObjectApiParameters(): void
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
                'name' => ReportMetadata::TOOL_NAME,
                'arguments' => [
                    'idSite' => $this->idSite,
                    'apiModule' => 'Actions',
                    'apiAction' => 'getPageUrls',
                    'apiParameters' => new \stdClass(),
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        self::assertSame($reportUniqueId, $result->structuredContent['uniqueId'] ?? null);
    }

    public function testRejectsUniqueIdWithNonEmptyListApiParametersAtSchemaLevel(): void
    {
        $report = $this->findAnyReportMetadata($this->idSite);
        self::assertNotNull($report);
        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

        $this->assertInvalidSchemaArguments([
            'idSite' => $this->idSite,
            'reportUniqueId' => $uniqueId,
            'apiParameters' => ['flat'],
        ]);
    }

    public function testRejectsUniqueIdWithNonEmptyObjectApiParametersAtSchemaLevel(): void
    {
        $report = $this->findAnyReportMetadata($this->idSite);
        self::assertNotNull($report);
        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

        $this->assertInvalidSchemaPayload(json_encode([
            'jsonrpc' => '2.0',
            'id' => __METHOD__,
            'method' => 'tools/call',
            'params' => [
                'name' => ReportMetadata::TOOL_NAME,
                'arguments' => [
                    'idSite' => $this->idSite,
                    'reportUniqueId' => $uniqueId,
                    'apiParameters' => ['idGoal' => 1],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testRejectsModuleActionWithNonEmptyListApiParametersAtSchemaLevel(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $error = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            ReportMetadata::TOOL_NAME,
            [
                'idSite' => $this->idSite,
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
        $this->assertInvalidSchemaPayload(json_encode([
            'jsonrpc' => '2.0',
            'id' => __METHOD__,
            'method' => 'tools/call',
            'params' => [
                'name' => ReportMetadata::TOOL_NAME,
                'arguments' => [
                    'idSite' => $this->idSite,
                    'apiModule' => 'Actions',
                    'apiAction' => 'getPageUrls',
                    'apiParameters' => 'bad',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testRejectsNumericApiParametersAtSchemaLevel(): void
    {
        $this->assertInvalidSchemaPayload(json_encode([
            'jsonrpc' => '2.0',
            'id' => __METHOD__,
            'method' => 'tools/call',
            'params' => [
                'name' => ReportMetadata::TOOL_NAME,
                'arguments' => [
                    'idSite' => $this->idSite,
                    'apiModule' => 'Actions',
                    'apiAction' => 'getPageUrls',
                    'apiParameters' => 123,
                ],
            ],
        ], JSON_THROW_ON_ERROR));
    }

    public function testMasksNoAccessAsNotFound(): void
    {
        $report = $this->findAnyReportMetadata($this->idSite);
        self::assertNotNull($report);
        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

        McpAuthTestHelper::asNoAccessUser(function () use ($uniqueId): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            McpTestHelper::callToolAndAssertError(
                $server,
                $sessionId,
                ReportMetadata::TOOL_NAME,
                ['idSite' => $this->idSite, 'reportUniqueId' => $uniqueId],
                'Report not found.',
                __METHOD__,
            );
        });
    }

    public function testToolForcesEnglishTranslations(): void
    {
        /** @var Translator $translator */
        $translator = StaticContainer::get(Translator::class);
        /** @var ProcessedReport $processedReport */
        $processedReport = StaticContainer::get(ProcessedReport::class);
        $originalLanguage = $translator->getCurrentLanguage();

        try {
            $differing = $this->findReportWithLanguageDifference($processedReport, $this->idSite, 'fr', 'en');
            self::assertNotNull(
                $differing,
                'Expected at least one report metadata entry with observable language difference (fr vs en).',
            );

            $translator->setCurrentLanguage('en');
            $englishMetadata = $processedReport->getReportMetadataByUniqueId($this->idSite, $differing['uniqueId']);
            self::assertIsArray($englishMetadata);

            $translator->setCurrentLanguage('fr');

            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            $content = McpTestHelper::callToolAndAssertSuccess(
                $server,
                $sessionId,
                ReportMetadata::TOOL_NAME,
                ['idSite' => $this->idSite, 'reportUniqueId' => $differing['uniqueId']],
                __METHOD__,
            );

            self::assertSame($englishMetadata['category'] ?? null, $content['category'] ?? null);
            self::assertSame($englishMetadata['name'] ?? null, $content['name'] ?? null);
        } finally {
            $translator->setCurrentLanguage($originalLanguage);
        }
    }

    public function testSchemaDeclaresSelectorRulesWithoutTopLevelCombinators(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-report-metadata-schema');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        $tool = null;
        foreach ($result->tools as $candidate) {
            if ($candidate->name === ReportMetadata::TOOL_NAME) {
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
        $apiParameters = $properties['apiParameters'] ?? null;
        self::assertIsArray($apiParameters);
        self::assertSame('object', $apiParameters['type'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAnyReportMetadata(int $idSite): ?array
    {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);

        foreach ($metadata as $report) {
            $uniqueId = $report['uniqueId'] ?? null;
            $module = $report['module'] ?? null;
            $action = $report['action'] ?? null;
            if (!is_string($uniqueId) || !is_string($module) || !is_string($action)) {
                continue;
            }

            return $report;
        }

        return null;
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
     * @return array<string, mixed>|null
     */
    private function findAnySubtableReportMetadata(int $idSite): ?array
    {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, true, true);

        foreach ($metadata as $report) {
            if (!$this->isSubtableMetadataRow($report)) {
                continue;
            }

            $uniqueId = $report['uniqueId'] ?? null;
            $module = $report['module'] ?? null;
            $action = $report['action'] ?? null;
            $parameters = $report['parameters'] ?? [];
            if (!is_string($uniqueId) || !is_string($module) || !is_string($action)) {
                continue;
            }
            if (!is_array($parameters)) {
                continue;
            }

            return $report;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertInvalidSchemaArguments(array $arguments): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ReportMetadata::TOOL_NAME,
            $arguments,
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);
        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportMetadata::TOOL_NAME . "':",
            $message->message,
        );
    }

    private function assertInvalidSchemaPayload(string $payload): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);
        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportMetadata::TOOL_NAME . "':",
            $message->message,
        );
    }

    /**
     * @return array{
     *   uniqueId: string,
     *   left: array<string, mixed>,
     *   right: array<string, mixed>,
     *   diffFields: list<string>
     * }|null
     */
    private function findReportWithLanguageDifference(
        ProcessedReport $processedReport,
        int $idSite,
        string $leftLanguage,
        string $rightLanguage,
    ): ?array {
        /** @var Translator $translator */
        $translator = StaticContainer::get(Translator::class);

        foreach ($this->findReportUniqueIdCandidates($idSite) as $uniqueId) {
            $translator->setCurrentLanguage($leftLanguage);
            $left = $processedReport->getReportMetadataByUniqueId($idSite, $uniqueId);

            $translator->setCurrentLanguage($rightLanguage);
            $right = $processedReport->getReportMetadataByUniqueId($idSite, $uniqueId);

            if (!is_array($left) || !is_array($right)) {
                continue;
            }
            /** @var array<string, mixed> $left */
            /** @var array<string, mixed> $right */

            /** @var list<string> $diffFields */
            $diffFields = [];
            $leftCategory = $left['category'] ?? null;
            $rightCategory = $right['category'] ?? null;
            if (
                is_string($leftCategory)
                && is_string($rightCategory)
                && !$this->isLikelyTranslationKey($leftCategory)
                && !$this->isLikelyTranslationKey($rightCategory)
                && $leftCategory !== $rightCategory
            ) {
                $diffFields[] = 'category';
            }
            $leftName = $left['name'] ?? null;
            $rightName = $right['name'] ?? null;
            if (
                is_string($leftName)
                && is_string($rightName)
                && !$this->isLikelyTranslationKey($leftName)
                && !$this->isLikelyTranslationKey($rightName)
                && $leftName !== $rightName
            ) {
                $diffFields[] = 'name';
            }

            if ($diffFields !== []) {
                return [
                    'uniqueId' => $uniqueId,
                    'left' => $left,
                    'right' => $right,
                    'diffFields' => $diffFields,
                ];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function findReportUniqueIdCandidates(int $idSite): array
    {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);
        $uniqueIds = [];

        foreach ($metadata as $report) {
            if ($this->isSubtableMetadataRow($report)) {
                continue;
            }

            $uniqueId = $report['uniqueId'] ?? null;
            if (!is_string($uniqueId)) {
                continue;
            }

            $uniqueIds[] = $uniqueId;
        }

        return $uniqueIds;
    }

    private function isLikelyTranslationKey(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9]+_[A-Za-z0-9_]+$/', $value) === 1;
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
}
