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
use Piwik\Http\BadRequestException;
use Piwik\Plugins\McpServer\Renderer\Mcp;
use Piwik\Plugins\McpServer\Support\Api\McpTransportResponse;
use PHPUnit\Framework\Assert;
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

        $result = $renderer->renderObject(new McpTransportResponse($response));

        self::assertSame('ok', $result);
        self::assertSame(200, $renderer->statusCode);
        self::assertSame(
            ['Content-Type: application/json', 'Mcp-Session-Id: session-1'],
            $renderer->headers
        );
    }

    public function testRenderObjectRejectsUnexpectedPayload(): void
    {
        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessage('MCP formatter expects a McpTransportResponse payload.');

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
}

final class TestableMcpRenderer extends Mcp
{
    public ?int $statusCode = null;

    /** @var array<int, string> */
    public array $headers = [];

    protected function applyStatusCode(int $statusCode): void
    {
        $this->statusCode = $statusCode;
    }

    protected function sendHeaderLine(string $header, bool $replace): void
    {
        Assert::assertFalse($replace);
        $this->headers[] = $header;
    }
}
