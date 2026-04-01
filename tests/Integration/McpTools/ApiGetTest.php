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
use Piwik\Plugins\McpServer\McpTools\ApiGet;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiGetTest extends IntegrationTestCase
{
    private string $originalRawApiAccessMode = 'none';

    public function setUp(): void
    {
        parent::setUp();

        $this->originalRawApiAccessMode = McpTestHelper::getRawApiAccessMode();
    }

    public function tearDown(): void
    {
        McpTestHelper::setRawApiAccessMode($this->originalRawApiAccessMode);

        parent::tearDown();
    }

    public function testReadModeReturnsKnownReadMethodByMethodSelector(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['method' => ' API.getMatomoVersion '],
            __METHOD__,
        );

        self::assertSame('API', $content['module'] ?? null);
        self::assertSame('getMatomoVersion', $content['action'] ?? null);
        self::assertSame('API.getMatomoVersion', $content['method'] ?? null);
        self::assertIsArray($content['parameters'] ?? null);
        self::assertSame('read', $content['operationCategory'] ?? null);
        self::assertArrayNotHasKey('classificationConfidence', $content);
        self::assertArrayNotHasKey('classificationReason', $content);
    }

    public function testFullModeReturnsKnownMutatingMethodByModuleAndActionSelectors(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['module' => ' usersmanager ', 'action' => ' adduser '],
            __METHOD__,
        );

        self::assertSame('UsersManager', $content['module'] ?? null);
        self::assertSame('addUser', $content['action'] ?? null);
        self::assertSame('UsersManager.addUser', $content['method'] ?? null);
        self::assertIsArray($content['parameters'] ?? null);
        self::assertSame('create', $content['operationCategory'] ?? null);
        self::assertArrayNotHasKey('classificationConfidence', $content);
        self::assertArrayNotHasKey('classificationReason', $content);
    }

    public function testReadModeRejectsWriteOnlyMethod(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['method' => 'UsersManager.addUser'],
            'API method not found or unavailable.',
            __METHOD__,
        );
    }

    public function testReadModeRejectsNonHeuristicMethod(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['method' => 'UsersManager.hasSuperUserAccess'],
            'API method not found or unavailable.',
            __METHOD__,
        );
    }

    public function testFullModeReturnsKnownNonHeuristicMethod(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['method' => 'UsersManager.hasSuperUserAccess'],
            __METHOD__,
        );

        self::assertSame('UsersManager.hasSuperUserAccess', $content['method'] ?? null);
    }

    public function testReadModeRejectsBlockedProxyLikeMethod(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['method' => 'API.getProcessedReport'],
            'API method not found or unavailable.',
            __METHOD__,
        );
    }

    public function testFullModeRejectsBlockedProxyLikeMethodBySplitSelector(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['module' => 'TreemapVisualization', 'action' => 'getTreemapData'],
            'API method not found or unavailable.',
            __METHOD__,
        );
    }

    public function testReadModeRejectsGetMetadata(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['method' => 'API.getMetadata'],
            'API method not found or unavailable.',
            __METHOD__,
        );
    }

    public function testReadModeRejectsGetReportMetadata(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['method' => 'API.getReportMetadata'],
            'API method not found or unavailable.',
            __METHOD__,
        );
    }

    public function testReadModeKeepsGetSuggestedValuesForSegmentAvailable(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['method' => 'API.getSuggestedValuesForSegment'],
            __METHOD__,
        );

        self::assertSame('API.getSuggestedValuesForSegment', $content['method'] ?? null);
    }

    public function testRejectsIncompleteSplitSelectorAtSchemaLevel(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            ['module' => 'UsersManager'],
            __METHOD__,
        );

        self::assertStringContainsString("Invalid parameters for tool '" . ApiGet::TOOL_NAME . "':", $message->message);
    }

    public function testRejectsMissingSelectorAtSchemaLevel(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            ApiGet::TOOL_NAME,
            [],
            __METHOD__,
        );

        self::assertStringContainsString("Invalid parameters for tool '" . ApiGet::TOOL_NAME . "':", $message->message);
    }

    public function testRejectsMixedSelectorStyleAtSchemaLevel(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ApiGet::TOOL_NAME,
            ['method' => 'API.getMatomoVersion', 'module' => 'API'],
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ApiGet::TOOL_NAME . "':",
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

        $apiGetTool = null;
        foreach ($result->tools as $tool) {
            if ($tool->name === ApiGet::TOOL_NAME) {
                $apiGetTool = $tool;
                break;
            }
        }

        self::assertNotNull($apiGetTool);
        /** @var array<string, mixed> $inputSchema */
        $inputSchema = $apiGetTool->inputSchema;
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
        self::assertArrayHasKey('method', $properties);
        self::assertArrayHasKey('module', $properties);
        self::assertArrayHasKey('action', $properties);
    }

    public function testNoneModeHidesAndRejectsToolCall(): void
    {
        McpTestHelper::setRawApiAccessMode('none');
        self::assertNotContains(ApiGet::TOOL_NAME, $this->listToolNamesForCurrentConfig());

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            ApiGet::TOOL_NAME,
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
