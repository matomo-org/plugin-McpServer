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
    }
}
