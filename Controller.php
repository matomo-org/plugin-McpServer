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
use Piwik\Container\StaticContainer;
use Piwik\Log\LoggerInterface;

class Controller extends \Piwik\Plugin\Controller
{
    public function mcp(): void
    {
        $server = $this->buildServer();
        $request = $this->createRequestFromGlobals();
        $transport = new StreamableHttpTransport($request);

        $result = $server->run($transport);
        $this->emit($result);
    }

    protected function buildServer(): Server
    {
        $logger = StaticContainer::get(LoggerInterface::class);
        $tmpPath = StaticContainer::get('path.tmp');

        if (!is_string($tmpPath)) {
            throw new \RuntimeException('Temporary path is not configured.');
        }

        return (new McpServerFactory())->createServer(
            $logger,
            $tmpPath,
            StaticContainer::getContainer()
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
