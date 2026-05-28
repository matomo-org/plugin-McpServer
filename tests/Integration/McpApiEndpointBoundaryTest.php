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
use Piwik\Date;
use Piwik\FrontController;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\API;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Access\McpAccessGate;
use Piwik\Plugins\McpServer\Support\Access\McpAccessLevel;
use Piwik\Plugins\McpServer\Support\Api\InternalApiAccessGuard;
use Piwik\Plugins\McpServer\Support\Api\InternalToolCaller;
use Piwik\Plugins\McpServer\Support\Api\InternalToolCatalog;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcErrorResponseFactory;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcRequestIdExtractor;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointGuard;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointSpec;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\UsersManager\Model as UsersManagerModel;
use Piwik\Tests\Framework\Fixture;
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

    private string $originalMaximumAllowedMcpAccessLevel = McpAccessLevel::UNLIMITED;

    private ?string $anonymousAccessBackup = null;

    private bool $createdAnonymousUser = false;

    private int $idSite = 0;

    private int $idSiteOther = 0;

    public function setUp(): void
    {
        parent::setUp();
        $this->originalGet = $_GET;
        $this->originalNestedApiInvocationCount = $this->getNestedApiInvocationCount();
        $this->originalRootApiMethod = (string) ApiRequest::getRootApiRequestMethod();
        $this->originalEnableMcpValue = (bool) StaticContainer::get(SystemSettings::class)->enableMcp->getValue();
        $this->originalMaximumAllowedMcpAccessLevel = StaticContainer::get(SystemSettings::class)
            ->getMaximumAllowedMcpAccessLevel();
        $this->idSite = Fixture::createWebsite('2010-01-01 00:00:00', 0, 'Boundary Test Site', 'https://boundary.test');
        $this->idSiteOther = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'Boundary Other Site',
            'https://boundary-other.test',
        );
    }

    public function tearDown(): void
    {
        $_GET = $this->originalGet;
        $this->setMcpEnabled($this->originalEnableMcpValue);
        $this->setMaximumAllowedMcpAccessLevel($this->originalMaximumAllowedMcpAccessLevel);
        $this->restoreAnonymousAccessForSite($this->idSite);
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
        self::assertIsString($output);
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

    public function testAnonymousWithViewAccessIsRejectedBeforeMcpEnabledCheck(): void
    {
        $this->setMcpEnabled(true);
        $this->setAnonymousAccessForSite($this->idSite, 'view');
        $originalTokenAuth = McpAuthTestHelper::captureCurrentTokenAuth();

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        try {
            McpAuthTestHelper::switchToAnonymous();

            $api = $this->createApiWithRequest(
                $this->createRequest(McpTestHelper::makeInitializeRequest('anonymous-view-1')),
            );
            $response = $api->mcp();
            $error = McpTestHelper::decodeError($response);

            self::assertSame(401, $response->getStatusCode());
            self::assertSame('Bearer realm="mcp"', $response->getHeaderLine('WWW-Authenticate'));
            self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
            self::assertSame(McpEndpointSpec::UNAUTHORIZED_ERROR, $error->message);
            self::assertSame('anonymous-view-1', $error->id);
        } finally {
            McpAuthTestHelper::restoreAuth($originalTokenAuth);
        }
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

    public function testPrivilegeCapAllowsViewUserWhenMaximumIsView(): void
    {
        $this->setMcpEnabled(true);
        $this->setMaximumAllowedMcpAccessLevel(McpAccessLevel::VIEW);

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        McpAuthTestHelper::asViewUserForSite($this->idSite, function (): void {
            $api = $this->createApiWithRequest($this->createRequest(McpTestHelper::makeInitializeRequest('view-1')));
            $response = $api->mcp();

            self::assertSame(200, $response->getStatusCode());
            McpTestHelper::decodeResponse($response);
        });
    }

    public function testPrivilegeCapRejectsWriteUserWhenMaximumIsView(): void
    {
        $this->setMcpEnabled(true);
        $this->setMaximumAllowedMcpAccessLevel(McpAccessLevel::VIEW);

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        McpAuthTestHelper::asWriteUserForSite($this->idSite, function (): void {
            $api = $this->createApiWithRequest($this->createRequest(McpTestHelper::makeInitializeRequest('write-1')));
            $response = $api->mcp();
            $error = McpTestHelper::decodeError($response);

            self::assertSame(403, $response->getStatusCode());
            self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
            self::assertSame(
                'Authenticated MCP access has too high privilege level. Maximum of View access level is allowed.',
                $error->message,
            );
            self::assertSame('write-1', $error->id);
        });
    }

    public function testPrivilegeCapRejectsAdminUserWhenMaximumIsWrite(): void
    {
        $this->setMcpEnabled(true);
        $this->setMaximumAllowedMcpAccessLevel(McpAccessLevel::WRITE);

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        McpAuthTestHelper::asAdminUserForSite($this->idSite, function (): void {
            $api = $this->createApiWithRequest($this->createRequest(McpTestHelper::makeInitializeRequest('admin-1')));
            $response = $api->mcp();
            $error = McpTestHelper::decodeError($response);

            self::assertSame(403, $response->getStatusCode());
            self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
            self::assertSame(
                'Authenticated MCP access has too high privilege level. Maximum of Write access level is allowed.',
                $error->message,
            );
            self::assertSame('admin-1', $error->id);
        });
    }

    public function testPrivilegeCapUsesHighestPrivilegeAcrossSites(): void
    {
        $this->setMcpEnabled(true);
        $this->setMaximumAllowedMcpAccessLevel(McpAccessLevel::WRITE);

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        McpAuthTestHelper::asUserWithSiteAccessLevels(
            ['view' => [$this->idSite], 'admin' => [$this->idSiteOther]],
            function (): void {
                $api = $this->createApiWithRequest(
                    $this->createRequest(McpTestHelper::makeInitializeRequest('mixed-1')),
                );
                $response = $api->mcp();
                $error = McpTestHelper::decodeError($response);

                self::assertSame(403, $response->getStatusCode());
                self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
                self::assertSame(
                    'Authenticated MCP access has too high privilege level. Maximum of Write access level is allowed.',
                    $error->message,
                );
                self::assertSame('mixed-1', $error->id);
            },
        );
    }

    public function testPrivilegeCapRejectsSuperUserWhenMaximumIsAdmin(): void
    {
        $this->setMcpEnabled(true);
        $this->setMaximumAllowedMcpAccessLevel(McpAccessLevel::ADMIN);
        Access::getInstance()->setSuperUserAccess(true);

        $_GET['module'] = 'API';
        $_GET['method'] = 'McpServer.mcp';
        $_GET['format'] = 'mcp';

        $api = $this->createApiWithRequest($this->createRequest(McpTestHelper::makeInitializeRequest('superuser-1')));
        $response = $api->mcp();
        $error = McpTestHelper::decodeError($response);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
        self::assertSame(
            'Authenticated MCP access has too high privilege level. Maximum of Admin access level is allowed.',
            $error->message,
        );
        self::assertSame('superuser-1', $error->id);
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
                new McpAccessGate(StaticContainer::get(SystemSettings::class)),
                new InternalApiAccessGuard(),
                new InternalToolCatalog(),
                new InternalToolCaller(),
                StaticContainer::get(LoggerInterface::class),
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
                new McpAccessGate(StaticContainer::get(SystemSettings::class)),
                new InternalApiAccessGuard(),
                new InternalToolCatalog(),
                new InternalToolCaller(),
                StaticContainer::get(LoggerInterface::class),
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

    private function setMaximumAllowedMcpAccessLevel(string $maximumAllowedMcpAccessLevel): void
    {
        $settings = StaticContainer::get(SystemSettings::class);
        $hadSuperUserAccess = Access::getInstance()->hasSuperUserAccess();
        Access::getInstance()->setSuperUserAccess(true);

        try {
            $settings->maximumMcpAccessLevel->setValue($maximumAllowedMcpAccessLevel);
        } finally {
            Access::getInstance()->setSuperUserAccess($hadSuperUserAccess);
        }
    }

    private function setAnonymousAccessForSite(int $idSite, string $access): void
    {
        $model = new UsersManagerModel();
        if (!$model->userExists('anonymous')) {
            $model->addUser('anonymous', 'not_a_hash', 'anonymous@example.com', Date::now()->getDatetime());
            $this->createdAnonymousUser = true;
        }

        if ($this->anonymousAccessBackup === null) {
            $usersAccess = $model->getUsersAccessFromSite($idSite);
            $this->anonymousAccessBackup = $usersAccess['anonymous'] ?? 'noaccess';
        }

        $model->deleteUserAccess('anonymous', [$idSite]);
        if ($access !== 'noaccess') {
            $model->addUserAccess('anonymous', $access, [$idSite]);
        }
    }

    private function restoreAnonymousAccessForSite(int $idSite): void
    {
        if ($this->anonymousAccessBackup === null) {
            return;
        }

        $model = new UsersManagerModel();
        $model->deleteUserAccess('anonymous', [$idSite]);
        if ($this->anonymousAccessBackup !== 'noaccess') {
            $model->addUserAccess('anonymous', $this->anonymousAccessBackup, [$idSite]);
        }

        if ($this->createdAnonymousUser) {
            $model->deleteUser('anonymous');
            $this->createdAnonymousUser = false;
        }

        $this->anonymousAccessBackup = null;
    }
}
