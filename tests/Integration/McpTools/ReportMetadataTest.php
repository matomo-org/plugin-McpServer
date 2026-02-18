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
use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;
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
            'https://report-metadata.test'
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
            __METHOD__
        );

        self::assertSame(ToolOutputSecurity::buildForTool(ReportMetadata::TOOL_NAME), $content['security'] ?? null);
        self::assertSame($uniqueId, $content['uniqueId'] ?? null);
        self::assertArrayHasKey('metadata', $content);
        self::assertIsArray($content['metadata']);
        self::assertArrayHasKey('parameters', $content);
        self::assertIsArray($content['parameters']);
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
            __METHOD__
        );

        self::assertSame($reportUniqueId, $content['uniqueId'] ?? null);
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
            __METHOD__
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
            __METHOD__
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
            __METHOD__
        );

        self::assertSame(ToolOutputSecurity::buildForTool(ReportMetadata::TOOL_NAME), $content['security'] ?? null);
        self::assertSame($uniqueId, $content['uniqueId'] ?? null);
    }

    public function testRejectsCombinedUniqueIdAndApiModuleAtSchemaLevel(): void
    {
        $report = $this->findAnyReportMetadata($this->idSite);
        self::assertNotNull($report);
        $uniqueId = $report['uniqueId'] ?? null;
        self::assertIsString($uniqueId);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ReportMetadata::TOOL_NAME,
            ['idSite' => $this->idSite, 'reportUniqueId' => $uniqueId, 'apiModule' => 'Actions'],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);
        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportMetadata::TOOL_NAME . "':",
            $message->message ?? ''
        );
    }

    public function testRejectsMissingSelectorAtSchemaLevel(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ReportMetadata::TOOL_NAME,
            ['idSite' => $this->idSite],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportMetadata::TOOL_NAME . "':",
            $message->message ?? ''
        );
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
                __METHOD__
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
                'Expected at least one report metadata entry with observable language difference (fr vs en).'
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
                __METHOD__
            );

            self::assertSame($englishMetadata['category'] ?? null, $content['category'] ?? null);
            self::assertSame($englishMetadata['name'] ?? null, $content['name'] ?? null);
        } finally {
            $translator->setCurrentLanguage($originalLanguage);
        }
    }

    public function testSchemaDeclaresSelectorAlternatives(): void
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
        self::assertArrayHasKey('oneOf', $inputSchema);
        self::assertIsArray($inputSchema['oneOf']);

        /** @var array<int, array<string, mixed>> $alternatives */
        $alternatives = $inputSchema['oneOf'];
        self::assertCount(2, $alternatives);
        self::assertSame(['reportUniqueId'], $alternatives[0]['required'] ?? null);
        self::assertSame(['apiModule', 'apiAction'], $alternatives[1]['required'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findAnyReportMetadata(int $idSite): ?array
    {
        $metadata = ApiModuleApi::getInstance()->getReportMetadata((string) $idSite, false, false, false, false);

        foreach ($metadata as $report) {
            if (!is_array($report)) {
                continue;
            }

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
        string $rightLanguage
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
            if (!is_array($report) || $this->isSubtableMetadataRow($report)) {
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
