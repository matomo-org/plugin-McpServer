<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Matomo\Dependencies\McpServer\Http\Discovery\Psr17Factory;
use Matomo\Dependencies\McpServer\Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Matomo\Dependencies\McpServer\Mcp\Server;
use Matomo\Dependencies\McpServer\Mcp\Server\Transport\StreamableHttpTransport;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\Log\LoggerInterface;
use Piwik\NoAccessException;
use Piwik\Piwik;
use Psr\Container\ContainerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;

class Controller extends \Piwik\Plugin\Controller
{
    public function __construct(
        private LoggerInterface $logger,
        private SessionStoreInterface $sessionStore,
        private McpServerFactory $factory,
        private ContainerInterface $container
    ) {
    }

    public function mcp(): void
    {
        $request = $this->createRequestFromGlobals();

        // Accept any authentication method that Matomo accepts.
        // We intentionally do not enforce header-only auth here yet.
        // When auth fails, we still provide a Bearer challenge as client guidance.
        try {
            Piwik::checkUserHasSomeViewAccess();
        } catch (NoAccessException $e) {
            $response = (new Psr17Factory())
                ->createResponse(401)
                ->withHeader('WWW-Authenticate', 'Bearer realm="mcp"');
            $this->emit($response);
            return;
        }

        $server = $this->buildServer();
        $transport = new StreamableHttpTransport($request);

        $result = $server->run($transport);
        $this->emit($result);
    }

    protected function buildServer(): Server
    {
        return $this->factory->createServer(
            $this->logger,
            $this->sessionStore,
            $this->container
        );
    }

    protected function createRequestFromGlobals(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequestFromGlobals();
    }

    protected function emit(ResponseInterface $response): void
    {
        (new SapiEmitter())->emit($response);
    }
}
