<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpServerFactoryTest extends TestCase
{
    public function testInitializeResponseHasExpectedServerInfoAndCapabilities(): void
    {
        $server = McpTestHelper::buildServer();
        $payload = McpTestHelper::makeInitializeRequest('init-1');

        $response = McpTestHelper::postJson($server, $payload);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseInitialize($message);
        $pluginVersion = (string) Manager::getInstance()->getVersion('McpServer');

        self::assertSame('Matomo MCP Server', $result->serverInfo->name);
        self::assertSame($pluginVersion, $result->serverInfo->version);

        $capabilities = $result->capabilities;

        self::assertTrue($capabilities->tools);
        self::assertNull($capabilities->toolsListChanged);
        self::assertFalse($capabilities->resources);
        self::assertNull($capabilities->resourcesSubscribe);
        self::assertNull($capabilities->resourcesListChanged);
        self::assertFalse($capabilities->prompts);
        self::assertNull($capabilities->promptsListChanged);
        self::assertFalse($capabilities->logging);
        self::assertFalse($capabilities->completions);
    }
}
