<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiCallQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\McpTools\ApiCallCreate;
use Piwik\Plugins\McpServer\McpTools\ApiCallDelete;
use Piwik\Plugins\McpServer\McpTools\ApiCallFull;
use Piwik\Plugins\McpServer\McpTools\ApiCallRead;
use Piwik\Plugins\McpServer\McpTools\ApiCallUpdate;
use Piwik\Plugins\McpServer\McpTools\ApiGet;
use Piwik\Plugins\McpServer\McpTools\ApiList;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;
use Piwik\Plugins\McpServer\tests\Framework\FixedMcpToolsProvider;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\StatefulSystemSettingsStub;
use Psr\Container\ContainerInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class RawApiToolVisibilityTest extends TestCase
{
    public function testRawApiListToolIsVisibleWhenRawAccessModeAllowsDirectApiAccess(): void
    {
        $toolsWhenRead = $this->listToolNamesForMode('read');
        self::assertContains(ApiCallRead::TOOL_NAME, $toolsWhenRead);
        self::assertContains('matomo_api_get', $toolsWhenRead);
        self::assertContains('matomo_api_list', $toolsWhenRead);
        self::assertNotContains(ApiCallFull::TOOL_NAME, $toolsWhenRead);

        $toolsWhenCreate = $this->listToolNamesForMode('create');
        self::assertContains(ApiCallCreate::TOOL_NAME, $toolsWhenCreate);
        self::assertContains('matomo_api_get', $toolsWhenCreate);
        self::assertContains('matomo_api_list', $toolsWhenCreate);

        $toolsWhenUpdate = $this->listToolNamesForMode('update');
        self::assertContains(ApiCallUpdate::TOOL_NAME, $toolsWhenUpdate);
        self::assertContains('matomo_api_get', $toolsWhenUpdate);
        self::assertContains('matomo_api_list', $toolsWhenUpdate);

        $toolsWhenDelete = $this->listToolNamesForMode('delete');
        self::assertContains(ApiCallDelete::TOOL_NAME, $toolsWhenDelete);
        self::assertContains('matomo_api_get', $toolsWhenDelete);
        self::assertContains('matomo_api_list', $toolsWhenDelete);

        $toolsWhenFull = $this->listToolNamesForMode('full');
        self::assertContains(ApiCallRead::TOOL_NAME, $toolsWhenFull);
        self::assertContains(ApiCallCreate::TOOL_NAME, $toolsWhenFull);
        self::assertContains(ApiCallUpdate::TOOL_NAME, $toolsWhenFull);
        self::assertContains(ApiCallDelete::TOOL_NAME, $toolsWhenFull);
        self::assertContains(ApiCallFull::TOOL_NAME, $toolsWhenFull);
        self::assertContains('matomo_api_get', $toolsWhenFull);
        self::assertContains('matomo_api_list', $toolsWhenFull);
    }

    public function testRawApiGetToolHasFullAnnotationsWhenVisible(): void
    {
        $toolsWhenRead = $this->listToolsByNameForMode('read');

        self::assertArrayHasKey('matomo_api_get', $toolsWhenRead);
        $toolWhenRead = $toolsWhenRead['matomo_api_get'];
        self::assertNotNull($toolWhenRead->annotations);
        self::assertTrue($toolWhenRead->annotations->readOnlyHint);
        self::assertFalse($toolWhenRead->annotations->destructiveHint);
        self::assertTrue($toolWhenRead->annotations->idempotentHint);
        self::assertFalse($toolWhenRead->annotations->openWorldHint);

        $toolsWhenFull = $this->listToolsByNameForMode('full');

        self::assertArrayHasKey('matomo_api_get', $toolsWhenFull);
        $toolWhenFull = $toolsWhenFull['matomo_api_get'];
        self::assertNotNull($toolWhenFull->annotations);
        self::assertTrue($toolWhenFull->annotations->readOnlyHint);
        self::assertFalse($toolWhenFull->annotations->destructiveHint);
        self::assertTrue($toolWhenFull->annotations->idempotentHint);
        self::assertFalse($toolWhenFull->annotations->openWorldHint);
    }

    public function testRawApiCallToolsHaveExpectedAnnotationsWhenVisible(): void
    {
        $toolsWhenRead = $this->listToolsByNameForMode('read');

        self::assertArrayHasKey(ApiCallRead::TOOL_NAME, $toolsWhenRead);
        $toolWhenRead = $toolsWhenRead[ApiCallRead::TOOL_NAME];
        self::assertNotNull($toolWhenRead->annotations);
        self::assertTrue($toolWhenRead->annotations->readOnlyHint);
        self::assertFalse($toolWhenRead->annotations->destructiveHint);
        self::assertTrue($toolWhenRead->annotations->idempotentHint);
        self::assertFalse($toolWhenRead->annotations->openWorldHint);

        $toolsWhenFull = $this->listToolsByNameForMode('full');

        self::assertArrayHasKey(ApiCallCreate::TOOL_NAME, $toolsWhenFull);
        self::assertNotNull($toolsWhenFull[ApiCallCreate::TOOL_NAME]->annotations);
        self::assertFalse($toolsWhenFull[ApiCallCreate::TOOL_NAME]->annotations->readOnlyHint);
        self::assertFalse($toolsWhenFull[ApiCallCreate::TOOL_NAME]->annotations->destructiveHint);

        self::assertArrayHasKey(ApiCallUpdate::TOOL_NAME, $toolsWhenFull);
        self::assertNotNull($toolsWhenFull[ApiCallUpdate::TOOL_NAME]->annotations);
        self::assertFalse($toolsWhenFull[ApiCallUpdate::TOOL_NAME]->annotations->readOnlyHint);
        self::assertFalse($toolsWhenFull[ApiCallUpdate::TOOL_NAME]->annotations->destructiveHint);

        self::assertArrayHasKey(ApiCallDelete::TOOL_NAME, $toolsWhenFull);
        self::assertNotNull($toolsWhenFull[ApiCallDelete::TOOL_NAME]->annotations);
        self::assertFalse($toolsWhenFull[ApiCallDelete::TOOL_NAME]->annotations->readOnlyHint);
        self::assertTrue($toolsWhenFull[ApiCallDelete::TOOL_NAME]->annotations->destructiveHint);

        self::assertArrayHasKey(ApiCallFull::TOOL_NAME, $toolsWhenFull);
        $toolWhenFull = $toolsWhenFull[ApiCallFull::TOOL_NAME];
        self::assertNotNull($toolWhenFull->annotations);
        self::assertFalse($toolWhenFull->annotations->readOnlyHint);
        self::assertTrue($toolWhenFull->annotations->destructiveHint);
        self::assertFalse($toolWhenFull->annotations->idempotentHint);
        self::assertFalse($toolWhenFull->annotations->openWorldHint);
    }

    /**
     * @return list<string>
     */
    private function listToolNamesForMode(string $rawApiAccessMode): array
    {
        return array_keys($this->listToolsByNameForMode($rawApiAccessMode));
    }

    /**
     * @return array<string, Tool>
     */
    private function listToolsByNameForMode(string $rawApiAccessMode): array
    {
        $systemSettings = new StatefulSystemSettingsStub();
        $systemSettings->currentRawApiAccessMode = $rawApiAccessMode;

        $factory = new McpServerFactory(
            $this->createMock(LoggerInterface::class),
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider($this->buildRawApiTools($systemSettings)),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-tools-1');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        $toolsByName = [];
        foreach ($result->tools as $tool) {
            $toolsByName[$tool->name] = $tool;
        }

        return $toolsByName;
    }

    /**
     * @return list<McpTool>
     */
    private function buildRawApiTools(StatefulSystemSettingsStub $systemSettings): array
    {
        $apiCallQueryService = $this->createMock(ApiCallQueryServiceInterface::class);
        $methodSummaryQueryService = $this->createMock(ApiMethodSummaryQueryServiceInterface::class);
        $paginationResponder = new PaginatedCollectionResponder(new CursorPaginator());

        return [
            new ApiCallCreate($apiCallQueryService, $methodSummaryQueryService, $systemSettings),
            new ApiCallDelete($apiCallQueryService, $methodSummaryQueryService, $systemSettings),
            new ApiCallFull($apiCallQueryService, $methodSummaryQueryService, $systemSettings),
            new ApiCallRead($apiCallQueryService, $methodSummaryQueryService, $systemSettings),
            new ApiCallUpdate($apiCallQueryService, $methodSummaryQueryService, $systemSettings),
            new ApiGet($methodSummaryQueryService, $systemSettings),
            new ApiList($methodSummaryQueryService, $paginationResponder, $systemSettings),
        ];
    }
}
