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
class McpProtocolErrorTest extends TestCase
{
    public function testUnknownToolReturnsErrorResponse(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-1');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testInvalidJsonReturnsErrorResponse(): void
    {
        $server = McpTestHelper::buildServer();

        $response = McpTestHelper::postJson($server, '{invalid-json');
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::PARSE_ERROR, $message->code);
    }

    public function testUnsupportedMethodReturnsErrorResponse(): void
    {
        $server = McpTestHelper::buildServer();

        $response = McpTestHelper::sendRequest($server, 'GET');
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_REQUEST, $message->code);
    }

    public function testSendRequestRejectsAuthorizationHeader(): void
    {
        $server = McpTestHelper::buildServer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Do not pass Authorization header');

        McpTestHelper::sendRequest($server, 'GET', '', ['Authorization' => 'Bearer custom-token']);
    }

    public function testSendRequestRejectsAuthorizationHeaderCaseInsensitive(): void
    {
        $server = McpTestHelper::buildServer();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Do not pass Authorization header');

        McpTestHelper::sendRequest($server, 'GET', '', ['authorization' => 'Bearer custom-token']);
    }
}
