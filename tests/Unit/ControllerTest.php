<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use Matomo\Dependencies\McpServer\Http\Discovery\Psr17Factory;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\Plugins\McpServer\Controller;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ControllerTest extends TestCase
{
    public function testMcpEmitsResponseForInitialize(): void
    {
        $factory = new Psr17Factory();
        $request = $factory
            ->createServerRequest('POST', 'https://example.test/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(McpTestHelper::makeInitializeRequest('init-1')));

        $controller = new TestController();
        $controller->setRequest($request);
        $controller->mcp();

        $response = $controller->getCapturedResponse();
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(200, $response->getStatusCode());

        McpTestHelper::decodeResponse($response);
    }
}

final class TestController extends Controller
{
    private ServerRequestInterface $request;
    private ?ResponseInterface $response = null;

    public function setRequest(ServerRequestInterface $request): void
    {
        $this->request = $request;
    }

    public function getCapturedResponse(): ?ResponseInterface
    {
        return $this->response;
    }

    protected function createRequestFromGlobals(): ServerRequestInterface
    {
        return $this->request;
    }

    protected function emit(ResponseInterface $response): void
    {
        $this->response = $response;
    }
}
