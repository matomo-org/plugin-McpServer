<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Renderer;

use Matomo\Dependencies\McpServer\Http\Discovery\Psr17Factory;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Piwik\Http\BadRequestException;
use Piwik\Plugins\McpServer\Renderer\Mcp;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpTest extends TestCase
{
    public function testConstructorRejectsNonMcpMethod(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('format=mcp can only be used with method=McpServer.mcp.');

        new TestableMcpRenderer(['method' => 'API.getMatomoVersion']);
    }

    public function testRenderObjectReturnsBodyAndForwardsStatusAndHeaders(): void
    {
        $renderer = new TestableMcpRenderer(['method' => 'McpServer.mcp']);
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Mcp-Session-Id', 'session-1')
            ->withBody($factory->createStream('ok'));

        $result = $renderer->renderObject($response);

        self::assertSame('ok', $result);
        self::assertSame(200, $renderer->statusCode);
        self::assertSame(
            [
                ['header' => 'Content-Type: application/json', 'replace' => false],
                ['header' => 'Mcp-Session-Id: session-1', 'replace' => false],
            ],
            $renderer->headers
        );
    }

    public function testRenderObjectRejectsUnexpectedPayload(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('MCP formatter expects a PSR-7 response payload.');

        $renderer = new TestableMcpRenderer(['method' => 'McpServer.mcp']);
        $renderer->renderObject((object) ['value' => 'invalid']);
    }

    public function testRenderArrayIsRejected(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('MCP formatter cannot render array responses.');

        $renderer = new TestableMcpRenderer(['method' => 'McpServer.mcp']);
        $renderer->renderArray(['invalid']);
    }

    public function testRenderExceptionReturnsJsonRpcInvalidRequestPayload(): void
    {
        $renderer = new TestableMcpRenderer(['method' => 'McpServer.mcp']);

        $payload = $renderer->renderException(
            'Bad request payload',
            new BadRequestException('bad', 400)
        );

        self::assertSame(
            '{"jsonrpc":"2.0","id":"","error":{"code":-32600,"message":"Bad request payload"}}',
            $payload
        );
        self::assertSame('Content-Type: application/json', $renderer->headers[0]['header']);
        self::assertTrue($renderer->headers[0]['replace']);
    }

    public function testRenderExceptionReturnsJsonRpcInternalErrorPayload(): void
    {
        $renderer = new TestableMcpRenderer(['method' => 'McpServer.mcp']);

        $payload = $renderer->renderException([], new \RuntimeException('boom'));

        self::assertSame(
            sprintf(
                '{"jsonrpc":"2.0","id":"","error":{"code":%d,"message":"Internal endpoint error."}}',
                JsonRpcError::INTERNAL_ERROR
            ),
            $payload
        );
    }
}

final class TestableMcpRenderer extends Mcp
{
    public ?int $statusCode = null;

    /** @var array<int, array{header: string, replace: bool}> */
    public array $headers = [];

    protected function applyStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    protected function sendHeaderLine(string $header, bool $replace): void
    {
        $this->headers[] = [
            'header' => $header,
            'replace' => $replace,
        ];
    }
}
