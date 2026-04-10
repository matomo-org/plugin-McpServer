<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Matomo\Dependencies\McpServer\Http\Discovery\Psr17Factory;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\Access;
use Piwik\API\Request as ApiRequest;
use Piwik\Container\StaticContainer;
use Piwik\FrontController;
use Piwik\Plugins\McpServer\API;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcErrorResponseFactory;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcRequestIdExtractor;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointGuard;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointSpec;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpApiEndpointBoundaryTest extends IntegrationTestCase
{
    /** @var array<string, mixed> */
    private array $originalGet = [];

    private int $originalNestedApiInvocationCount = 0;

    private string $originalRootApiMethod = '';

    private bool $originalEnableMcpValue = false;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalGet = $_GET;
        $this->originalNestedApiInvocationCount = $this->getNestedApiInvocationCount();
        $this->originalRootApiMethod = (string) ApiRequest::getRootApiRequestMethod();
        $this->originalEnableMcpValue = (bool) StaticContainer::get(SystemSettings::class)->enableMcp->getValue();
    }

    public function tearDown(): void
    {
        $_GET = $this->originalGet;
        $this->setMcpEnabled($this->originalEnableMcpValue);
        $this->setNestedApiInvocationCount($this->originalNestedApiInvocationCount);
        ApiRequest::setIsRootRequestApiRequest($this->originalRootApiMethod);
        Access::getInstance()->setSuperUserAccess(false);
        parent::tearDown();
    }

    public function testGuardErrorEchoesRequestId(): void
    {
        $_GET['module'] = 'API';
        $_GET['method'] = 'API.getMatomoVersion';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest(McpTestHelper::makeInitializeRequest('guard-1')));
        $response = $api->mcp();
        $error = McpTestHelper::decodeError($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
        self::assertSame('guard-1', $error->id);
    }

    public function testUnauthorizedErrorEchoesRequestId(): void
    {
        Access::getInstance()->setSuperUserAccess(false);
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest(McpTestHelper::makeInitializeRequest('auth-1')));
        $response = $api->mcp();
        $error = McpTestHelper::decodeError($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="mcp"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
        self::assertSame('auth-1', $error->id);
    }

    public function testGuardErrorWithInvalidJsonFallsBackToEmptyId(): void
    {
        $_GET['module'] = 'API';
        $_GET['method'] = 'API.getMatomoVersion';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest('{invalid-json'));
        $response = $api->mcp();
        $error = McpTestHelper::decodeError($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $error->id);
    }

    public function testNestedRootStateWithoutMockedRootHelpersReturnsGuardError(): void
    {
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        ApiRequest::setIsRootRequestApiRequest('McpServer.mcp');
        $this->setNestedApiInvocationCount(2);

        $api = $this->createApiWithRequestOnly($this->createRequest(McpTestHelper::makeInitializeRequest('nested-1')));
        $response = $api->mcp();
        $error = McpTestHelper::decodeError($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
        self::assertSame('nested-1', $error->id);
        self::assertStringStartsWith('MCP endpoint requires a root API request:', $error->message);
    }

    public function testBulkRootMethodStateWithoutMockedRootHelpersReturnsGuardError(): void
    {
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        ApiRequest::setIsRootRequestApiRequest('API.getBulkRequest');
        $this->setNestedApiInvocationCount(1);

        $api = $this->createApiWithRequestOnly($this->createRequest(McpTestHelper::makeInitializeRequest('bulk-1')));
        $response = $api->mcp();
        $error = McpTestHelper::decodeError($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
        self::assertSame('bulk-1', $error->id);
        self::assertStringStartsWith('MCP endpoint requires a root API request:', $error->message);
    }

    public function testApiFrontControllerDispatchHitsMcpEndpointWithoutApiMocking(): void
    {
        Access::getInstance()->setSuperUserAccess(true);
        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $output = FrontController::getInstance()->fetchDispatch('API');
        self::assertNotSame('', trim($output));

        $decoded = \json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'Expected JSON-RPC response payload from API dispatch.');
        self::assertSame('2.0', $decoded['jsonrpc'] ?? null);
        self::assertTrue(isset($decoded['error']), 'Expected JSON-RPC error for empty request body.');
        self::assertIsInt($decoded['error']['code'] ?? null);
    }

    public function testDisabledMcpReturnsForbiddenErrorWhenTopLevelIdExists(): void
    {
        $this->setMcpEnabled(false);
        Access::getInstance()->setSuperUserAccess(true);

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest(McpTestHelper::makeInitializeRequest('disabled-1')));
        $response = $api->mcp();
        $error = McpTestHelper::decodeError($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
        self::assertSame(McpEndpointSpec::DISABLED_ERROR, $error->message);
        self::assertSame('disabled-1', $error->id);
    }

    public function testDisabledMcpAndUnauthenticatedReturnsUnauthorizedChallenge(): void
    {
        $this->setMcpEnabled(false);
        Access::getInstance()->setSuperUserAccess(false);

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest(
            $this->createRequest(McpTestHelper::makeInitializeRequest('disabled-auth-1')),
        );
        $response = $api->mcp();
        $error = McpTestHelper::decodeError($response);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('Bearer realm="mcp"', $response->getHeaderLine('WWW-Authenticate'));
        self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
        self::assertSame(McpEndpointSpec::UNAUTHORIZED_ERROR, $error->message);
        self::assertSame('disabled-auth-1', $error->id);
    }

    public function testDisabledMcpReturnsForbiddenWithEmptyBodyWhenTopLevelIdMissing(): void
    {
        $this->setMcpEnabled(false);
        Access::getInstance()->setSuperUserAccess(true);

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $initialize = \json_decode(McpTestHelper::makeInitializeRequest('batch-1'), true, 512, \JSON_THROW_ON_ERROR);
        $batchPayload = \json_encode([$initialize], \JSON_THROW_ON_ERROR);

        $api = $this->createApiWithRequest($this->createRequest($batchPayload));
        $response = $api->mcp();

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Content-Type'));
        self::assertSame('', McpTestHelper::getResponseBody($response));
    }

    private function createRequest(string $payload): ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return $factory
            ->createServerRequest('POST', 'https://example.test/index.php?module=API&method=McpServer.mcp&format=mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream($payload));
    }

    private function createApiWithRequest(ServerRequestInterface $request): API
    {
        $factory = StaticContainer::get(McpServerFactory::class);

        $api = $this
            ->getMockBuilder(API::class)
            ->setConstructorArgs([
                $factory,
                new McpEndpointGuard(),
                new JsonRpcErrorResponseFactory(),
                new JsonRpcRequestIdExtractor(),
                StaticContainer::get(SystemSettings::class),
            ])
            ->onlyMethods(['createRequestFromGlobals', 'isCurrentApiRequestRoot', 'getRootApiRequestMethod'])
            ->getMock();

        $api->method('createRequestFromGlobals')
            ->willReturn($request);
        $api->method('isCurrentApiRequestRoot')
            ->willReturn(true);
        $api->method('getRootApiRequestMethod')
            ->willReturn('McpServer.mcp');

        return $api;
    }

    private function createApiWithRequestOnly(ServerRequestInterface $request): API
    {
        $factory = StaticContainer::get(McpServerFactory::class);

        $api = $this
            ->getMockBuilder(API::class)
            ->setConstructorArgs([
                $factory,
                new McpEndpointGuard(),
                new JsonRpcErrorResponseFactory(),
                new JsonRpcRequestIdExtractor(),
                StaticContainer::get(SystemSettings::class),
            ])
            ->onlyMethods(['createRequestFromGlobals'])
            ->getMock();

        $api->method('createRequestFromGlobals')
            ->willReturn($request);

        return $api;
    }

    private function getNestedApiInvocationCount(): int
    {
        $property = new \ReflectionProperty(ApiRequest::class, 'nestedApiInvocationCount');
        $property->setAccessible(true);

        $value = $property->getValue();

        return is_int($value) ? $value : 0;
    }

    private function setNestedApiInvocationCount(int $count): void
    {
        $property = new \ReflectionProperty(ApiRequest::class, 'nestedApiInvocationCount');
        $property->setAccessible(true);
        $property->setValue($count);
    }

    private function setMcpEnabled(bool $isEnabled): void
    {
        $settings = StaticContainer::get(SystemSettings::class);
        $hadSuperUserAccess = Access::getInstance()->hasSuperUserAccess();
        Access::getInstance()->setSuperUserAccess(true);

        try {
            $settings->enableMcp->setValue($isEnabled);
        } finally {
            Access::getInstance()->setSuperUserAccess($hadSuperUserAccess);
        }
    }
}
