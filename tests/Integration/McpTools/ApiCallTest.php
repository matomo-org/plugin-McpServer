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
use Piwik\API\Request;
use Piwik\DataTable\DataTableInterface;
use Piwik\DataTable\Renderer\Json;
use Piwik\Plugins\McpServer\McpTools\ApiCall;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiCallTest extends IntegrationTestCase
{
    private string $originalRawApiAccessMode = 'none';
    private int $idSite = 0;

    protected static function configureFixture($fixture): void
    {
        parent::configureFixture($fixture);

        $fixture->createSuperUser = true;
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->originalRawApiAccessMode = McpTestHelper::getRawApiAccessMode();
        $this->idSite = Fixture::createWebsite(
            '2015-01-01 00:00:00',
            0,
            'MCP Raw API Call Test Site',
            'https://raw-api-call.test',
        );

        $tracker = Fixture::getTracker(
            $this->idSite,
            '2015-01-03 12:00:00',
            $defaultInit = true,
            $useLocal = true,
        );
        $tracker->setUrl('https://raw-api-call.test/page-a');
        Fixture::checkResponse($tracker->doTrackPageView('page-a'));
        $tracker->setUrl('https://raw-api-call.test/page-b');
        Fixture::checkResponse($tracker->doTrackPageView('page-b'));
    }

    public function tearDown(): void
    {
        McpTestHelper::setRawApiAccessMode($this->originalRawApiAccessMode);

        parent::tearDown();
    }

    public function testReadModeCallsKnownReadMethodByMethodSelector(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ApiCall::TOOL_NAME,
            ['method' => ' API.getMatomoVersion '],
            __METHOD__,
        );

        $resolvedMethod = $content['resolvedMethod'] ?? null;
        self::assertIsArray($resolvedMethod);
        self::assertSame('API', $resolvedMethod['module'] ?? null);
        self::assertSame('getMatomoVersion', $resolvedMethod['action'] ?? null);
        self::assertSame('API.getMatomoVersion', $resolvedMethod['method'] ?? null);
        self::assertArrayHasKey('result', $content);
        self::assertIsString($content['result']);
        self::assertNotSame('', $content['result']);
    }

    public function testReadModeCallsKnownReadMethodByModuleAndActionSelector(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ApiCall::TOOL_NAME,
            ['module' => ' API ', 'action' => ' getMatomoVersion '],
            __METHOD__,
        );

        $resolvedMethod = $content['resolvedMethod'] ?? null;
        self::assertIsArray($resolvedMethod);
        self::assertSame('API.getMatomoVersion', $resolvedMethod['method'] ?? null);
        self::assertArrayHasKey('result', $content);
        self::assertIsString($content['result']);
    }

    public function testReadModeNormalizesDataTableResponse(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $baseline = Request::processRequest('Actions.getPageUrls', [
            'idSite' => $this->idSite,
            'period' => 'day',
            'date' => '2015-01-03',
        ], []);
        self::assertInstanceOf(DataTableInterface::class, $baseline);

        $renderer = new Json();
        $renderer->setTable($baseline);
        $expected = json_decode($renderer->render(), true, 512, JSON_THROW_ON_ERROR);

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ApiCall::TOOL_NAME,
            [
                'method' => 'Actions.getPageUrls',
                'parameters' => [
                    'idSite' => $this->idSite,
                    'period' => 'day',
                    'date' => '2015-01-03',
                ],
            ],
            __METHOD__,
        );

        self::assertSame($expected, $content['result'] ?? null);
    }

    public function testReadModeRejectsWriteOnlyMethod(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ApiCall::TOOL_NAME,
            ['method' => 'UsersManager.addUser'],
            'API method not found or unavailable.',
            __METHOD__,
        );
    }

    public function testFullModeAttemptsMutatingMethodCall(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $result = McpTestHelper::callTool(
            $server,
            $sessionId,
            ApiCall::TOOL_NAME,
            ['method' => 'UsersManager.addUser'],
            __METHOD__,
        );

        McpTestHelper::assertToolError($result);
        $content = $result->content[0] ?? null;
        self::assertInstanceOf(\Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent::class, $content);
        $errorText = $content->text;
        self::assertIsString($errorText);
        self::assertTrue(
            $errorText === 'Matomo API request failed.'
            || str_starts_with($errorText, 'Matomo API request failed: '),
        );
    }

    public function testRejectsReservedParameterKeys(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ApiCall::TOOL_NAME,
            [
                'method' => 'API.getMatomoVersion',
                'parameters' => ['format' => 'json'],
            ],
            "Unsupported parameters key 'format'.",
            __METHOD__,
        );
    }

    public function testRejectsMissingSelectorAtSchemaLevel(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            ApiCall::TOOL_NAME,
            [],
            __METHOD__,
        );

        self::assertStringContainsString(
            "Invalid parameters for tool '" . ApiCall::TOOL_NAME . "':",
            $message->message,
        );
    }

    public function testRejectsMixedSelectorStyleAtSchemaLevel(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ApiCall::TOOL_NAME,
            ['method' => 'API.getMatomoVersion', 'module' => 'API'],
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ApiCall::TOOL_NAME . "':",
            $message->message ?? '',
        );
    }

    public function testSchemaDeclaresFlatSelectorsWithoutTopLevelCombinators(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest(__METHOD__);

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        $apiCallTool = null;
        foreach ($result->tools as $tool) {
            if ($tool->name === ApiCall::TOOL_NAME) {
                $apiCallTool = $tool;
                break;
            }
        }

        self::assertNotNull($apiCallTool);
        /** @var array<string, mixed> $inputSchema */
        $inputSchema = $apiCallTool->inputSchema;
        self::assertArrayNotHasKey('oneOf', $inputSchema);
        self::assertArrayNotHasKey('allOf', $inputSchema);
        self::assertArrayNotHasKey('anyOf', $inputSchema);
        self::assertArrayHasKey('not', $inputSchema);
        self::assertIsArray($inputSchema['not']);
    }

    public function testNoneModeHidesAndRejectsToolCall(): void
    {
        McpTestHelper::setRawApiAccessMode('none');
        self::assertNotContains(ApiCall::TOOL_NAME, $this->listToolNamesForCurrentConfig());

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ApiCall::TOOL_NAME,
            ['method' => 'API.getMatomoVersion'],
            __METHOD__,
        );
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    /**
     * @return list<string>
     */
    private function listToolNamesForCurrentConfig(): array
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest(__METHOD__);

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        return array_values(array_map(static fn($tool) => $tool->name, $result->tools));
    }
}
