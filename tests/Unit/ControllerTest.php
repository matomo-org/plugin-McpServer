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
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\Access;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Controller;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class ControllerTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalMcpServerConfig = null;

    public function setUp(): void
    {
        parent::setUp();

        $originalConfig = Config::getInstance()->McpServer ?? null;
        $this->originalMcpServerConfig = is_array($originalConfig) ? $originalConfig : null;
    }

    public function tearDown(): void
    {
        Config::getInstance()->McpServer = $this->originalMcpServerConfig;

        parent::tearDown();
    }

    public function testMcpEmitsResponseForInitialize(): void
    {
        try {
            Access::getInstance()->setSuperUserAccess(true);
            Config::getInstance()->McpServer = ['log_tool_calls' => 1];

            $request = $this->createRequest();
            $factory = $this->createFactory();
            $capturedResponse = null;

            $controller = $this->createController($factory, $request, $capturedResponse);
            $controller->mcp();

            $response = $capturedResponse;
            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(200, $response->getStatusCode());

            McpTestHelper::decodeResponse($response);
        } finally {
            Access::getInstance()->setSuperUserAccess(false);
        }
    }

    public function testMcpRejectsUnauthenticatedRequestAndSendsBearerChallengeHint(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 1];

        $request = $this->createRequest();
        $factory = $this->createFactory();
        $capturedResponse = null;

        $controller = $this->createController($factory, $request, $capturedResponse);
        $controller->mcp();

        $response = $capturedResponse;
        self::assertInstanceOf(ResponseInterface::class, $response);
        self::assertSame(401, $response->getStatusCode());

        // This challenge advertises preferred header auth, but auth is not header-only yet.
        self::assertSame('Bearer realm="mcp"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('', (string) $response->getBody());
    }

    private function createRequest(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream(McpTestHelper::makeInitializeRequest('init-1'));

        return $factory
            ->createServerRequest('POST', 'https://example.test/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($body);
    }

    private function createFactory(): McpServerFactory
    {
        return new McpServerFactory(
            $this->createMock(LoggerInterface::class),
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter()
        );
    }

    private function createController(
        McpServerFactory $factory,
        ServerRequestInterface $request,
        ?ResponseInterface &$capturedResponse
    ): Controller {
        $controller = $this
            ->getMockBuilder(Controller::class)
            ->setConstructorArgs([$factory])
            ->onlyMethods(['createRequestFromGlobals', 'emit'])
            ->getMock();

        $controller->method('createRequestFromGlobals')
            ->willReturn($request);
        $controller->expects(self::once())
            ->method('emit')
            ->willReturnCallback(static function (ResponseInterface $response) use (&$capturedResponse): void {
                $capturedResponse = $response;
            });

        return $controller;
    }
}
