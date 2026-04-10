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
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use PHPUnit\Framework\TestCase;
use Piwik\Access;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\API;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcErrorResponseFactory;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcRequestIdExtractor;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointGuard;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointSpec;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
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

    public function testMcpReturnsResponseForInitialize(): void
    {
        Access::getInstance()->setSuperUserAccess(true);
        Config::getInstance()->McpServer = ['log_tool_calls' => 1];
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest());
        $result = $api->mcp();

        self::assertInstanceOf(ResponseInterface::class, $result);
        $response = $result;
        self::assertSame(200, $response->getStatusCode());

        McpTestHelper::decodeResponse($response);
    }

    public function testMcpRejectsRequestWithoutMcpFormat(): void
    {
        $this->expectException(\Piwik\Http\BadRequestException::class);
        $this->expectExceptionMessage('MCP endpoint requires format=mcp.');

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $api = $this->createApiWithRequest($this->createRequest());
        $api->mcp();
    }

    public function testMcpRejectsRequestWithoutApiModule(): void
    {
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest());
        $result = $api->mcp();

        $this->assertGuardErrorResponse($result);
    }

    public function testMcpRejectsRequestWithoutMcpMethod(): void
    {
        $_GET['module'] = 'API';
        $_GET['method'] = 'API.getMatomoVersion';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest());
        $result = $api->mcp();

        $this->assertGuardErrorResponse($result);
    }

    public function testMcpRejectsNestedApiRequest(): void
    {
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest(), false, 'McpServer.mcp');
        $result = $api->mcp();

        $this->assertGuardErrorResponse($result);
    }

    public function testMcpRejectsApiBulkRequestContext(): void
    {
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest(), true, 'API.getBulkRequest');
        $result = $api->mcp();

        $this->assertGuardErrorResponse($result);
    }

    public function testMcpReturnsUnauthorizedChallengeWhenNoViewAccess(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 1];
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest());
        $result = $api->mcp();

        self::assertInstanceOf(ResponseInterface::class, $result);
        $response = $result;
        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="mcp"', $response->getHeaderLine('WWW-Authenticate'));
        $message = McpTestHelper::decodeError($response);
        self::assertSame(JsonRpcError::INVALID_REQUEST, $message->code);
        self::assertSame('Authentication required.', $message->message);
        self::assertSame('init-1', $message->id);
    }

    public function testMcpReturnsUnauthorizedChallengeWhenNoAccessExceptionIsWrapped(): void
    {
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $factory = $this->createFactory();

        $api = $this
            ->getMockBuilder(API::class)
            ->setConstructorArgs([
                $factory,
                new McpEndpointGuard(),
                new JsonRpcErrorResponseFactory(),
                new JsonRpcRequestIdExtractor(),
                $this->createMock(SystemSettings::class),
            ])
            ->onlyMethods([
                'createRequestFromGlobals',
                'isCurrentApiRequestRoot',
                'getRootApiRequestMethod',
                'checkUserHasSomeViewAccess',
            ])
            ->getMock();

        $api->method('createRequestFromGlobals')
            ->willReturn($this->createRequest());
        $api->method('isCurrentApiRequestRoot')
            ->willReturn(true);
        $api->method('getRootApiRequestMethod')
            ->willReturn('McpServer.mcp');
        $api->method('checkUserHasSomeViewAccess')
            ->willThrowException(
                new \RuntimeException('wrapped', 0, new \Piwik\NoAccessException('No access')),
            );

        $result = $api->mcp();

        self::assertSame(401, $result->getStatusCode());
        self::assertSame('Bearer realm="mcp"', $result->getHeaderLine('WWW-Authenticate'));
        $message = McpTestHelper::decodeError($result);
        self::assertSame(JsonRpcError::INVALID_REQUEST, $message->code);
        self::assertSame('Authentication required.', $message->message);
        self::assertSame('init-1', $message->id);
    }

    public function testMcpReturnsForbiddenErrorWhenMcpIsDisabledAndTopLevelIdExists(): void
    {
        Access::getInstance()->setSuperUserAccess(true);
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $request = $this->createRequest(McpTestHelper::makeInitializeRequest('disabled-1'));
        $api = $this->createApiWithRequest($request, true, 'McpServer.mcp', false);
        $response = $api->mcp();

        self::assertSame(403, $response->getStatusCode());
        $message = McpTestHelper::decodeError($response);
        self::assertSame(JsonRpcError::INVALID_REQUEST, $message->code);
        self::assertSame(McpEndpointSpec::DISABLED_ERROR, $message->message);
        self::assertSame('disabled-1', $message->id);
    }

    public function testMcpReturnsForbiddenWithoutBodyWhenMcpIsDisabledAndTopLevelIdIsMissing(): void
    {
        Access::getInstance()->setSuperUserAccess(true);
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $initialize = \json_decode(McpTestHelper::makeInitializeRequest('batch-1'), true, 512, \JSON_THROW_ON_ERROR);
        $batchPayload = \json_encode([$initialize], \JSON_THROW_ON_ERROR);
        $request = $this->createRequest($batchPayload);
        $api = $this->createApiWithRequest($request, true, 'McpServer.mcp', false);
        $response = $api->mcp();

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Content-Type'));
        self::assertSame('', McpTestHelper::getResponseBody($response));
    }

    public function testMcpReturnsInternalErrorResponseWhenRequestCreationFails(): void
    {
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $factory = $this->createFactory();

        $api = $this
            ->getMockBuilder(API::class)
            ->setConstructorArgs([
                $factory,
                new McpEndpointGuard(),
                new JsonRpcErrorResponseFactory(),
                new JsonRpcRequestIdExtractor(),
                $this->createMock(SystemSettings::class),
            ])
            ->onlyMethods(['createRequestFromGlobals', 'isCurrentApiRequestRoot', 'getRootApiRequestMethod'])
            ->getMock();

        $api->method('isCurrentApiRequestRoot')
            ->willReturn(true);
        $api->method('getRootApiRequestMethod')
            ->willReturn('McpServer.mcp');
        $api->method('createRequestFromGlobals')
            ->willThrowException(new \RuntimeException('boom'));

        $result = $api->mcp();

        self::assertSame(500, $result->getStatusCode());
        $message = McpTestHelper::decodeError($result);
        self::assertSame(JsonRpcError::INTERNAL_ERROR, $message->code);
        self::assertSame('Internal endpoint error.', $message->message);
        self::assertSame('', $message->id);
    }

    private function createRequest(?string $payload = null): ServerRequestInterface
    {
        $factory = new Psr17Factory();
        $requestPayload = $payload ?? McpTestHelper::makeInitializeRequest('init-1');
        $body = $factory->createStream($requestPayload);

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
            new ToolCallParameterFormatter(),
        );
    }

    private function createApiWithRequest(
        ServerRequestInterface $request,
        bool $isRootApiRequest = true,
        ?string $rootApiMethod = 'McpServer.mcp',
        bool $isMcpEnabled = true,
    ): API {
        $factory = $this->createFactory();

        $api = $this
            ->getMockBuilder(API::class)
            ->setConstructorArgs([
                $factory,
                new McpEndpointGuard(),
                new JsonRpcErrorResponseFactory(),
                new JsonRpcRequestIdExtractor(),
                $this->createMock(SystemSettings::class),
            ])
            ->onlyMethods([
                'createRequestFromGlobals',
                'isCurrentApiRequestRoot',
                'getRootApiRequestMethod',
                'isMcpEnabled',
            ])
            ->getMock();

        $api->method('createRequestFromGlobals')
            ->willReturn($request);
        $api->method('isCurrentApiRequestRoot')
            ->willReturn($isRootApiRequest);
        $api->method('getRootApiRequestMethod')
            ->willReturn($rootApiMethod);
        $api->method('isMcpEnabled')
            ->willReturn($isMcpEnabled);

        return $api;
    }

    private function assertGuardErrorResponse(ResponseInterface $response): void
    {
        self::assertSame(400, $response->getStatusCode());
        $message = McpTestHelper::decodeError($response);
        self::assertSame(JsonRpcError::INVALID_REQUEST, $message->code);
        self::assertStringStartsWith('MCP endpoint requires a root API request:', $message->message);
        self::assertSame('init-1', $message->id);
    }
}
