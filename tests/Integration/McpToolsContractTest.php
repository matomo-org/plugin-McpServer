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
use Piwik\ArchiveProcessor\Rules;
use Piwik\Cache;
use Piwik\Config;
use Piwik\Plugins\McpServer\McpTools\ApiCall;
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
        $this->runWithArchivingMode(
            browserTriggerEnabled: false,
            browserArchivingDisabledEnforce: 1,
            callback: function (): void {
                McpTestHelper::setRawApiAccessMode('none');

                $server = McpTestHelper::buildServer();
                $sessionId = McpTestHelper::initializeSession($server);
                $payload = McpTestHelper::makeListToolsRequest('list-1');

                $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
                $message = McpTestHelper::decodeResponse($response);
                $result = McpTestHelper::parseListTools($message);

                $toolNames = array_map(static fn($tool) => $tool->name, $result->tools);

                // Intentionally assert only McpServer-owned tools.
                // Other plugins may legitimately register additional tools.
                // We verify presence, not exclusivity, to keep this test plugin-scoped.
                self::assertContains('matomo_site_get', $toolNames);
                self::assertContains('matomo_site_list', $toolNames);
                self::assertContains('matomo_site_search', $toolNames);
                self::assertContains('matomo_segment_get', $toolNames);
                self::assertContains('matomo_segment_list', $toolNames);
                self::assertContains('matomo_dimension_list', $toolNames);
                self::assertContains('matomo_dimension_get', $toolNames);
                self::assertContains('matomo_goal_get', $toolNames);
                self::assertContains('matomo_goal_list', $toolNames);
                self::assertContains('matomo_report_list', $toolNames);
                self::assertContains('matomo_report_metadata', $toolNames);
                self::assertContains('matomo_report_processed', $toolNames);

                $expectedHintsByTool = [
                    'matomo_site_get' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_site_list' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_site_search' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_segment_get' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_segment_list' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_dimension_list' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_dimension_get' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_goal_get' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_goal_list' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_report_list' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_report_metadata' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => true,
                        'openWorldHint' => false,
                    ],
                    'matomo_report_processed' => [
                        'readOnlyHint' => true,
                        'destructiveHint' => false,
                        'idempotentHint' => false,
                        'openWorldHint' => false,
                    ],
                ];

                $toolsByName = [];
                foreach ($result->tools as $tool) {
                    $toolsByName[$tool->name] = $tool;
                }

                foreach ($expectedHintsByTool as $toolName => $expectedHints) {
                    self::assertArrayHasKey($toolName, $toolsByName);
                    $tool = $toolsByName[$toolName];
                    self::assertNotNull($tool->annotations);
                    self::assertSame($expectedHints['readOnlyHint'], $tool->annotations->readOnlyHint);
                    self::assertSame($expectedHints['destructiveHint'], $tool->annotations->destructiveHint);
                    self::assertSame($expectedHints['idempotentHint'], $tool->annotations->idempotentHint);
                    self::assertSame($expectedHints['openWorldHint'], $tool->annotations->openWorldHint);
                }
            },
        );
    }

    public function testReportProcessedReadOnlyHintIsDisabledWhenBrowserTriggerArchivingIsEnabled(): void
    {
        $this->runWithArchivingMode(
            browserTriggerEnabled: true,
            browserArchivingDisabledEnforce: 1,
            callback: function (): void {
                $hints = $this->fetchHintsByToolName('matomo_report_processed');

                self::assertFalse($hints['readOnlyHint']);
                self::assertFalse($hints['destructiveHint']);
                self::assertFalse($hints['idempotentHint']);
                self::assertFalse($hints['openWorldHint']);
            },
        );
    }

    public function testReportProcessedReadOnlyHintIsDisabledWhenBrowserSegmentArchivingIsAvailable(): void
    {
        $this->runWithArchivingMode(
            browserTriggerEnabled: false,
            browserArchivingDisabledEnforce: 0,
            callback: function (): void {
                $hints = $this->fetchHintsByToolName('matomo_report_processed');

                self::assertFalse($hints['readOnlyHint']);
            },
        );
    }

    /**
     * @return array{
     *     readOnlyHint: bool|null,
     *     destructiveHint: bool|null,
     *     idempotentHint: bool|null,
     *     openWorldHint: bool|null,
     * }
     */
    private function fetchHintsByToolName(string $toolName): array
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-dynamic');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        foreach ($result->tools as $tool) {
            if ($tool->name === $toolName) {
                return [
                    'readOnlyHint' => $tool->annotations->readOnlyHint ?? null,
                    'destructiveHint' => $tool->annotations->destructiveHint ?? null,
                    'idempotentHint' => $tool->annotations->idempotentHint ?? null,
                    'openWorldHint' => $tool->annotations->openWorldHint ?? null,
                ];
            }
        }

        self::fail(sprintf('Expected tool %s to be present in tools/list response.', $toolName));
    }

    private function runWithArchivingMode(
        bool $browserTriggerEnabled,
        int $browserArchivingDisabledEnforce,
        callable $callback,
    ): void {
        $config = Config::getInstance();
        $general = $config->General;
        if (!is_array($general)) {
            throw new \RuntimeException('Invalid Matomo general config state.');
        }

        $originalEnableBrowserArchivingTriggering = (int) ($general['enable_browser_archiving_triggering'] ?? 1);
        $originalBrowserArchivingDisabledEnforce = (int) ($general['browser_archiving_disabled_enforce'] ?? 0);
        $originalBrowserTriggerEnabled = Rules::isBrowserTriggerEnabled();

        try {
            $general['enable_browser_archiving_triggering'] = $browserTriggerEnabled ? 1 : 0;
            $general['browser_archiving_disabled_enforce'] = $browserArchivingDisabledEnforce;
            $config->General = $general;
            Rules::setBrowserTriggerArchiving($browserTriggerEnabled);
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

    public function testRawApiListToolIsHiddenWhenRawAccessModeIsNone(): void
    {
        McpTestHelper::setRawApiAccessMode('none');
        $toolsByName = $this->listToolsByNameForCurrentConfig();

        self::assertArrayNotHasKey(ApiCall::TOOL_NAME, $toolsByName);
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

        self::assertArrayHasKey(ApiCall::TOOL_NAME, $toolsByName);
        $callTool = $toolsByName[ApiCall::TOOL_NAME];
        self::assertNotNull($callTool->annotations);
        self::assertFalse($callTool->annotations->readOnlyHint);
        self::assertFalse($callTool->annotations->destructiveHint);
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

        self::assertArrayHasKey(ApiCall::TOOL_NAME, $toolsByName);
        $callTool = $toolsByName[ApiCall::TOOL_NAME];
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
