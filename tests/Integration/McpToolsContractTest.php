<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Piwik\Plugins\McpServer\McpTools\ApiCallCreate;
use Piwik\Plugins\McpServer\McpTools\ApiCallDelete;
use Piwik\Plugins\McpServer\McpTools\ApiCallFull;
use Piwik\Plugins\McpServer\McpTools\ApiCallRead;
use Piwik\Plugins\McpServer\McpTools\ApiCallUpdate;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpToolsContractTest extends IntegrationTestCase
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

    public function testToolsListContainsAllPluginTools(): void
    {
        McpTestHelper::setRawApiAccessMode('none');

        $toolsByName = $this->listToolsByNameForCurrentConfig();

        // Intentionally assert only McpServer-owned tools.
        // Other plugins may legitimately register additional tools.
        // We verify presence, not exclusivity, to keep this test plugin-scoped.
        $expectedReadOnlyToolNames = [
            'matomo_site_get',
            'matomo_site_list',
            'matomo_site_search',
            'matomo_segment_get',
            'matomo_segment_list',
            'matomo_dimension_list',
            'matomo_dimension_get',
            'matomo_goal_get',
            'matomo_goal_list',
            'matomo_report_list',
            'matomo_report_metadata',
            'matomo_report_processed',
        ];

        foreach ($expectedReadOnlyToolNames as $toolName) {
            self::assertArrayHasKey($toolName, $toolsByName);
            $tool = $toolsByName[$toolName];
            self::assertNotNull($tool->annotations);

            $message = sprintf('Unexpected annotation hints for tool %s.', $toolName);
            self::assertTrue($tool->annotations->readOnlyHint, $message);
            self::assertFalse($tool->annotations->destructiveHint, $message);
            self::assertTrue($tool->annotations->idempotentHint, $message);
            self::assertFalse($tool->annotations->openWorldHint, $message);
        }
    }

    public function testRawApiListToolIsHiddenWhenRawAccessModeIsNone(): void
    {
        McpTestHelper::setRawApiAccessMode('none');
        $toolsByName = $this->listToolsByNameForCurrentConfig();

        self::assertArrayNotHasKey(ApiCallRead::TOOL_NAME, $toolsByName);
        self::assertArrayNotHasKey(ApiCallCreate::TOOL_NAME, $toolsByName);
        self::assertArrayNotHasKey(ApiCallUpdate::TOOL_NAME, $toolsByName);
        self::assertArrayNotHasKey(ApiCallDelete::TOOL_NAME, $toolsByName);
        self::assertArrayNotHasKey(ApiCallFull::TOOL_NAME, $toolsByName);
        self::assertArrayNotHasKey('matomo_api_get', $toolsByName);
        self::assertArrayNotHasKey('matomo_api_list', $toolsByName);
    }

    public function testRawApiListToolIsVisibleWithExpectedAnnotationsWhenRawAccessModeIsRead(): void
    {
        McpTestHelper::setRawApiAccessMode('read');
        $toolsByName = $this->listToolsByNameForCurrentConfig();

        self::assertArrayHasKey('matomo_api_get', $toolsByName);
        $getTool = $toolsByName['matomo_api_get'];
        self::assertNotNull($getTool->annotations);
        self::assertTrue($getTool->annotations->readOnlyHint);
        self::assertFalse($getTool->annotations->destructiveHint);
        self::assertTrue($getTool->annotations->idempotentHint);
        self::assertFalse($getTool->annotations->openWorldHint);

        self::assertArrayHasKey('matomo_api_list', $toolsByName);
        $tool = $toolsByName['matomo_api_list'];
        self::assertNotNull($tool->annotations);
        self::assertTrue($tool->annotations->readOnlyHint);
        self::assertFalse($tool->annotations->destructiveHint);
        self::assertTrue($tool->annotations->idempotentHint);
        self::assertFalse($tool->annotations->openWorldHint);

        self::assertArrayHasKey(ApiCallRead::TOOL_NAME, $toolsByName);
        self::assertArrayNotHasKey(ApiCallFull::TOOL_NAME, $toolsByName);
        $callTool = $toolsByName[ApiCallRead::TOOL_NAME];
        self::assertNotNull($callTool->annotations);
        self::assertTrue($callTool->annotations->readOnlyHint);
        self::assertFalse($callTool->annotations->destructiveHint);
        self::assertTrue($callTool->annotations->idempotentHint);
        self::assertFalse($callTool->annotations->openWorldHint);
    }

    public function testRawApiListToolIsVisibleWithExpectedAnnotationsWhenRawAccessModeIsCreate(): void
    {
        McpTestHelper::setRawApiAccessMode('create');
        $toolsByName = $this->listToolsByNameForCurrentConfig();

        self::assertArrayHasKey('matomo_api_get', $toolsByName);
        self::assertArrayHasKey('matomo_api_list', $toolsByName);
        self::assertArrayHasKey(ApiCallCreate::TOOL_NAME, $toolsByName);
        self::assertArrayNotHasKey(ApiCallFull::TOOL_NAME, $toolsByName);

        $callTool = $toolsByName[ApiCallCreate::TOOL_NAME];
        self::assertNotNull($callTool->annotations);
        self::assertFalse($callTool->annotations->readOnlyHint);
        self::assertFalse($callTool->annotations->destructiveHint);
        self::assertFalse($callTool->annotations->idempotentHint);
        self::assertFalse($callTool->annotations->openWorldHint);
    }

    public function testRawApiListToolIsVisibleWithExpectedAnnotationsWhenRawAccessModeIsUpdate(): void
    {
        McpTestHelper::setRawApiAccessMode('update');
        $toolsByName = $this->listToolsByNameForCurrentConfig();

        self::assertArrayHasKey('matomo_api_get', $toolsByName);
        self::assertArrayHasKey('matomo_api_list', $toolsByName);
        self::assertArrayHasKey(ApiCallUpdate::TOOL_NAME, $toolsByName);
        self::assertArrayNotHasKey(ApiCallFull::TOOL_NAME, $toolsByName);

        $callTool = $toolsByName[ApiCallUpdate::TOOL_NAME];
        self::assertNotNull($callTool->annotations);
        self::assertFalse($callTool->annotations->readOnlyHint);
        self::assertFalse($callTool->annotations->destructiveHint);
        self::assertFalse($callTool->annotations->idempotentHint);
        self::assertFalse($callTool->annotations->openWorldHint);
    }

    public function testRawApiListToolIsVisibleWithExpectedAnnotationsWhenRawAccessModeIsDelete(): void
    {
        McpTestHelper::setRawApiAccessMode('delete');
        $toolsByName = $this->listToolsByNameForCurrentConfig();

        self::assertArrayHasKey('matomo_api_get', $toolsByName);
        $getTool = $toolsByName['matomo_api_get'];
        self::assertNotNull($getTool->annotations);
        self::assertTrue($getTool->annotations->readOnlyHint);
        self::assertFalse($getTool->annotations->openWorldHint);

        self::assertArrayHasKey('matomo_api_list', $toolsByName);
        $tool = $toolsByName['matomo_api_list'];
        self::assertNotNull($tool->annotations);
        self::assertTrue($tool->annotations->readOnlyHint);
        self::assertFalse($tool->annotations->openWorldHint);

        self::assertArrayHasKey(ApiCallDelete::TOOL_NAME, $toolsByName);
        self::assertArrayNotHasKey(ApiCallFull::TOOL_NAME, $toolsByName);
        $callTool = $toolsByName[ApiCallDelete::TOOL_NAME];
        self::assertNotNull($callTool->annotations);
        self::assertFalse($callTool->annotations->readOnlyHint);
        self::assertTrue($callTool->annotations->destructiveHint);
        self::assertFalse($callTool->annotations->idempotentHint);
        self::assertFalse($callTool->annotations->openWorldHint);
    }

    public function testRawApiListToolIsVisibleWithExpectedAnnotationsWhenRawAccessModeIsFull(): void
    {
        McpTestHelper::setRawApiAccessMode('full');
        $toolsByName = $this->listToolsByNameForCurrentConfig();

        self::assertArrayHasKey('matomo_api_get', $toolsByName);
        $getTool = $toolsByName['matomo_api_get'];
        self::assertNotNull($getTool->annotations);
        self::assertTrue($getTool->annotations->readOnlyHint);
        self::assertFalse($getTool->annotations->destructiveHint);
        self::assertTrue($getTool->annotations->idempotentHint);
        self::assertFalse($getTool->annotations->openWorldHint);

        self::assertArrayHasKey('matomo_api_list', $toolsByName);
        $tool = $toolsByName['matomo_api_list'];
        self::assertNotNull($tool->annotations);
        self::assertTrue($tool->annotations->readOnlyHint);
        self::assertFalse($tool->annotations->destructiveHint);
        self::assertTrue($tool->annotations->idempotentHint);
        self::assertFalse($tool->annotations->openWorldHint);

        self::assertArrayHasKey(ApiCallRead::TOOL_NAME, $toolsByName);
        self::assertArrayHasKey(ApiCallCreate::TOOL_NAME, $toolsByName);
        self::assertArrayHasKey(ApiCallUpdate::TOOL_NAME, $toolsByName);
        self::assertArrayHasKey(ApiCallDelete::TOOL_NAME, $toolsByName);
        self::assertArrayHasKey(ApiCallFull::TOOL_NAME, $toolsByName);
        $callTool = $toolsByName[ApiCallFull::TOOL_NAME];
        self::assertNotNull($callTool->annotations);
        self::assertFalse($callTool->annotations->readOnlyHint);
        self::assertTrue($callTool->annotations->destructiveHint);
        self::assertFalse($callTool->annotations->idempotentHint);
        self::assertFalse($callTool->annotations->openWorldHint);
    }

    /**
     * @return array<string, Tool>
     */
    private function listToolsByNameForCurrentConfig(): array
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest(__METHOD__);

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        $toolsByName = [];
        foreach ($result->tools as $tool) {
            $toolsByName[$tool->name] = $tool;
        }

        return $toolsByName;
    }
}
