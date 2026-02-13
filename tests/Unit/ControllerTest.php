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
use Matomo\Dependencies\McpServer\Mcp\Server;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\Access;
use Piwik\Plugins\McpServer\Controller;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Psr\Container\ContainerInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class ControllerTest extends TestCase
{
    public function testMcpEmitsResponseForInitialize(): void
    {
        try {
            Access::getInstance()->setSuperUserAccess(true);

            $request = $this->createRequest();

            $controller = $this->createController(McpTestHelper::buildServer());
            $controller->setRequest($request);
            $controller->mcp();

            $response = $controller->getCapturedResponse();
            self::assertInstanceOf(ResponseInterface::class, $response);
            self::assertSame(200, $response->getStatusCode());

            McpTestHelper::decodeResponse($response);
        } finally {
            Access::getInstance()->setSuperUserAccess(false);
        }
    }

    public function testMcpRejectsUnauthenticatedRequestAndSendsBearerChallengeHint(): void
    {
        $request = $this->createRequest();

        $controller = $this->createController();
        $controller->setRequest($request);
        $controller->mcp();

        $response = $controller->getCapturedResponse();
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

    private function createController(?Server $server = null): TestController
    {
        return new TestController(
            $this->createMock(LoggerInterface::class),
            new InMemorySessionStore(),
            new McpServerFactory(),
            $this->createMock(ContainerInterface::class),
            $server
        );
    }
}

final class TestController extends Controller
{
    private ?ServerRequestInterface $request = null;
    private ?ResponseInterface $response = null;
    private ?Server $server = null;

    public function __construct(
        LoggerInterface $logger,
        SessionStoreInterface $sessionStore,
        McpServerFactory $factory,
        ContainerInterface $container,
        ?Server $server = null
    ) {
        parent::__construct($logger, $sessionStore, $factory, $container);
        $this->server = $server;
    }

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
        if ($this->request === null) {
            throw new \RuntimeException('Request not set.');
        }

        return $this->request;
    }

    protected function emit(ResponseInterface $response): void
    {
        $this->response = $response;
    }

    protected function buildServer(): Server
    {
        if ($this->server === null) {
            throw new \RuntimeException('Server not set.');
        }

        return $this->server;
    }
}
