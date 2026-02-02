<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpToolsContractTest extends TestCase
{
    public function testToolsListIncludesMatomoHello(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-1');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        $toolNames = \array_map(static fn($tool) => $tool->name, $result->tools);

        self::assertContains('matomo_hello', $toolNames);
    }

    public function testMissingSessionIdReturnsError(): void
    {
        $server = McpTestHelper::buildServer();
        $payload = McpTestHelper::makeListToolsRequest('list-1');

        $response = McpTestHelper::postJson($server, $payload);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_REQUEST, $message->code);
        self::assertSame('A valid session id is REQUIRED for non-initialize requests.', $message->message);
    }

    public function testHelloToolCallReturnsExpectedContent(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest('matomo_hello', [], 'call-1');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertSame(['hello' => 'Matomo'], $result->structuredContent);
    }
}
