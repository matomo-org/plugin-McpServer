<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpToolsContractTest extends IntegrationTestCase
{
    public function testToolsListContainsAllPluginTools(): void
    {
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
                'readOnlyHint' => false,
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
    }
}
