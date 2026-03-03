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
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Piwik\Http\BadRequestException;
use Piwik\Plugins\McpServer\Renderer\Mcp;
use Piwik\Plugins\McpServer\Support\Api\McpTransportResponse;
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

    public function testRenderObjectEmitsTransportResponse(): void
    {
        $renderer = new TestableMcpRenderer(['method' => 'McpServer.mcp']);
        $factory = new Psr17Factory();
        $response = $factory->createResponse(200)->withBody($factory->createStream('ok'));

        $result = $renderer->renderObject(new McpTransportResponse($response));

        self::assertNull($result);
        self::assertSame($response, $renderer->emittedResponse);
        self::assertTrue($renderer->terminated);
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
    public ?ResponseInterface $emittedResponse = null;

    public bool $terminated = false;

    protected function emit(ResponseInterface $response): void
    {
        $this->emittedResponse = $response;
    }

    protected function terminate(): void
    {
        $this->terminated = true;
    }
}
