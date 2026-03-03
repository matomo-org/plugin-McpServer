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
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\Access;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\API;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Api\McpTransportResponse;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class APITest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalMcpServerConfig = null;

    /** @var array<string, mixed> */
    private array $originalGet = [];

    /** @var array<string, mixed> */
    private array $originalPost = [];

    public function setUp(): void
    {
        parent::setUp();

        $originalConfig = Config::getInstance()->McpServer ?? null;
        $this->originalMcpServerConfig = is_array($originalConfig) ? $originalConfig : null;

        $this->originalGet = $_GET;
        $this->originalPost = $_POST;
    }

    public function tearDown(): void
    {
        Config::getInstance()->McpServer = $this->originalMcpServerConfig;
        $_GET = $this->originalGet;
        $_POST = $this->originalPost;

        Access::getInstance()->setSuperUserAccess(false);

        parent::tearDown();
    }

    public function testMcpReturnsTransportResponseForInitialize(): void
    {
        Access::getInstance()->setSuperUserAccess(true);
        Config::getInstance()->McpServer = ['log_tool_calls' => 1];
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest());
        $result = $api->mcp();

        self::assertInstanceOf(McpTransportResponse::class, $result);
        $response = $result->response();
        self::assertSame(200, $response->getStatusCode());

        McpTestHelper::decodeResponse($response);
    }

    public function testMcpRejectsRequestWithoutMcpFormat(): void
    {
        $this->expectException(\Piwik\Http\BadRequestException::class);
        $this->expectExceptionMessage('MCP endpoint requires format=mcp.');

        $api = $this->createApiWithRequest($this->createRequest());
        $api->mcp();
    }

    public function testMcpReturnsUnauthorizedChallengeWhenNoViewAccess(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 1];
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest());
        $result = $api->mcp();

        self::assertInstanceOf(McpTransportResponse::class, $result);
        $response = $result->response();
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="mcp"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame('', (string) $response->getBody());
    }

    private function createRequest(): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $body = $factory->createStream(McpTestHelper::makeInitializeRequest('init-1'));

        return $factory
            ->createServerRequest('POST', 'https://example.test/index.php?module=API&method=McpServer.mcp&format=mcp')
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

    private function createApiWithRequest(ServerRequestInterface $request): API
    {
        $factory = $this->createFactory();

        $api = $this
            ->getMockBuilder(API::class)
            ->setConstructorArgs([$factory])
            ->onlyMethods(['createRequestFromGlobals'])
            ->getMock();

        $api->method('createRequestFromGlobals')
            ->willReturn($request);

        return $api;
    }
}
