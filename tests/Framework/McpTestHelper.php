<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Framework;

use Matomo\Dependencies\McpServer\Http\Discovery\Psr17Factory;
use Matomo\Dependencies\McpServer\Mcp\JsonRpc\MessageFactory;
use Matomo\Dependencies\McpServer\Mcp\Schema\ClientCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Schema\Implementation;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\MessageInterface;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\InitializeRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ListToolsRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\PingRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\InitializeResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\ListToolsResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Server;
use Matomo\Dependencies\McpServer\Mcp\Server\Transport\StreamableHttpTransport;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Piwik\Access;
use Piwik\Container\StaticContainer;
use Piwik\Plugins\McpServer\McpServerFactory;
use PHPUnit\Framework\Assert;

/**
 * @phpstan-import-type ToolData from Tool
 */
final class McpTestHelper
{
    public static function buildServer(): Server
    {
        $factory = StaticContainer::get(McpServerFactory::class);

        return $factory->createServer();
    }

    /**
     * @param array<string, mixed>|array<int, mixed>|string $payload
     * @param array<string, string> $headers
     */
    public static function postJson(Server $server, array|string $payload, array $headers = []): ResponseInterface
    {
        return self::sendRequest($server, 'POST', $payload, $headers);
    }

    public static function initializeSession(Server $server): string
    {
        $payload = self::makeInitializeRequest('init-1');
        $response = self::postJson($server, $payload);
        self::decodeResponse($response);

        $sessionId = $response->getHeaderLine('Mcp-Session-Id');
        Assert::assertNotSame('', $sessionId, 'Expected Mcp-Session-Id header on initialize response.');

        return $sessionId;
    }

    /**
     * @param array<string, mixed>|array<int, mixed>|string $payload
     * @param array<string, string> $headers
     */
    public static function sendRequest(
        Server $server,
        string $method,
        array|string $payload = '',
        array $headers = []
    ): ResponseInterface {
        foreach ($headers as $name => $_value) {
            if (\strtolower($name) === 'authorization') {
                throw new \InvalidArgumentException(
                    'Do not pass Authorization header to McpTestHelper::sendRequest(); auth is managed by the helper.'
                );
            }
        }

        $factory = new Psr17Factory();
        $uri = 'https://example.test/mcp';
        $tokenAuth = McpAuthTestHelper::getForcedTokenAuth() ?? Access::getInstance()->getTokenAuth();
        $request = $factory->createServerRequest($method, $uri);

        if ($payload !== '') {
            $json = \is_array($payload) ? \json_encode($payload, \JSON_THROW_ON_ERROR) : $payload;
            $request = $request->withBody($factory->createStream($json));
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        if ($tokenAuth !== null) {
            $request = $request->withHeader('Authorization', 'Bearer ' . $tokenAuth);
        }

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $transport = new StreamableHttpTransport($request);

        $hadAuthorizationHeader = array_key_exists('HTTP_AUTHORIZATION', $_SERVER);
        $previousAuthorizationHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if ($tokenAuth !== null) {
            McpAuthTestHelper::switchToTokenAuth($tokenAuth);
            $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tokenAuth;
        }

        try {
            return $server->run($transport);
        } finally {
            if ($hadAuthorizationHeader) {
                $_SERVER['HTTP_AUTHORIZATION'] = $previousAuthorizationHeader;
            } else {
                unset($_SERVER['HTTP_AUTHORIZATION']);
            }
        }
    }

    public static function decodeMessage(ResponseInterface $response): MessageInterface
    {
        $body = self::readBody($response);
        $messages = MessageFactory::make()->create($body);
        Assert::assertCount(1, $messages, 'Expected a single MCP response message.');
        Assert::assertNotInstanceOf(\Throwable::class, $messages[0], 'Expected a valid MCP message.');

        return $messages[0];
    }

    public static function getResponseBody(ResponseInterface $response): string
    {
        return self::readBody($response);
    }

    /**
     * @return Response<array<string, mixed>>
     */
    public static function decodeResponse(ResponseInterface $response): Response
    {
        $message = self::decodeMessage($response);
        Assert::assertInstanceOf(Response::class, $message, 'Expected MCP response message.');
        Assert::assertIsArray($message->result, 'Expected MCP response result to be an array.');

        /** @var Response<array<string, mixed>> $message */
        return $message;
    }

    public static function decodeError(ResponseInterface $response): Error
    {
        $message = self::decodeMessage($response);
        Assert::assertInstanceOf(Error::class, $message, 'Expected MCP error message.');

        return $message;
    }

    /**
     * @param Response<array<string, mixed>> $response
     */
    public static function parseInitialize(Response $response): InitializeResult
    {
        /**
         * @var array{
         *     protocolVersion: string,
         *     capabilities: array<string, mixed>,
         *     serverInfo: array<string, mixed>,
         *     instructions?: string,
         *     _meta?: array<string, mixed>,
         * } $result
         */
        $result = $response->result;

        return InitializeResult::fromArray($result);
    }

    /**
     * @param Response<array<string, mixed>> $response
     */
    public static function parseListTools(Response $response): ListToolsResult
    {
        /**
         * @var array{
         *     tools: array<ToolData>,
         *     nextCursor?: string
         * } $result
         */
        $result = $response->result;

        return ListToolsResult::fromArray($result);
    }

    /**
     * @param Response<array<string, mixed>> $response
     */
    public static function parseCallTool(Response $response): CallToolResult
    {
        /**
         * @var array{
         *     content: array<mixed>,
         *     isError?: bool,
         *     _meta?: array<string, mixed>,
         *     structuredContent?: array<string, mixed>
         * } $result
         */
        $result = $response->result;

        return CallToolResult::fromArray($result);
    }

    public static function makeInitializeRequest(string|int $id = '1'): string
    {
        $request = new InitializeRequest(
            MessageInterface::PROTOCOL_VERSION->value,
            new ClientCapabilities(),
            new Implementation('test-client', '1.0.0')
        );

        return self::encodeRequest($request, $id);
    }

    public static function makeListToolsRequest(string|int $id = '1'): string
    {
        $request = new ListToolsRequest();

        return self::encodeRequest($request, $id);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function makeCallToolRequest(string $name, array $arguments = [], string|int $id = '1'): string
    {
        $request = new CallToolRequest($name, $arguments);

        return self::encodeRequest($request, $id);
    }

    public static function makePingRequest(string|int $id = '1'): string
    {
        $request = new PingRequest();

        return self::encodeRequest($request, $id);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function callTool(
        Server $server,
        string $sessionId,
        string $name,
        array $arguments = [],
        string|int $id = '1'
    ): CallToolResult {
        $payload = self::makeCallToolRequest($name, $arguments, $id);
        $response = self::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = self::decodeResponse($response);

        return self::parseCallTool($message);
    }

    /**
     * @return array<string, mixed>
     */
    public static function assertToolSuccess(CallToolResult $result): array
    {
        Assert::assertFalse($result->isError);
        Assert::assertIsArray($result->structuredContent);

        return $result->structuredContent;
    }

    public static function assertToolError(CallToolResult $result, ?string $expectedText = null): void
    {
        Assert::assertTrue($result->isError);
        Assert::assertNotEmpty($result->content);

        if ($expectedText !== null) {
            $content = $result->content[0] ?? null;
            Assert::assertInstanceOf(TextContent::class, $content);
            Assert::assertSame($expectedText, $content->text);
        }
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function callToolAndAssertSuccess(
        Server $server,
        string $sessionId,
        string $name,
        array $arguments = [],
        string|int $id = '1'
    ): array {
        return self::assertToolSuccess(
            self::callTool($server, $sessionId, $name, $arguments, $id)
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function callToolAndAssertError(
        Server $server,
        string $sessionId,
        string $name,
        array $arguments = [],
        ?string $expectedText = null,
        string|int $id = '1'
    ): CallToolResult {
        $result = self::callTool($server, $sessionId, $name, $arguments, $id);
        self::assertToolError($result, $expectedText);

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function callToolExpectInvalidParams(
        Server $server,
        string $sessionId,
        string $name,
        array $arguments = [],
        string|int $id = '1'
    ): Error {
        $payload = self::makeCallToolRequest($name, $arguments, $id);
        $response = self::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = self::decodeError($response);
        Assert::assertSame(Error::INVALID_PARAMS, $message->code);

        return $message;
    }

    private static function encodeRequest(Request $request, string|int $id): string
    {
        return \json_encode($request->withId($id), \JSON_THROW_ON_ERROR);
    }

    private static function readBody(ResponseInterface $response): string
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->getContents();
    }
}
