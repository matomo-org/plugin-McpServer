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
use Matomo\Dependencies\McpServer\Mcp\Server\Transport\StreamableHttpTransport;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\NoAccessException;
use Piwik\Piwik;
use Piwik\Http\BadRequestException;
use Piwik\Plugins\McpServer\Support\Api\McpTransportResponse;
use Piwik\Request;

class API extends \Piwik\Plugin\API
{
    public function __construct(private McpServerFactory $factory)
    {
    }

    /**
     * @internal
     */
    public function mcp(): McpTransportResponse
    {
        $format = Request::fromRequest()->getStringParameter('format', '');
        if (strtolower($format) !== 'mcp') {
            throw new BadRequestException(
                'MCP endpoint requires format=mcp. Use module=API&method=McpServer.mcp&format=mcp.'
            );
        }

        $request = $this->createRequestFromGlobals();

        try {
            Piwik::checkUserHasSomeViewAccess();
        } catch (NoAccessException $e) {
            return new McpTransportResponse($this->createUnauthorizedResponse());
        }

        $server = $this->factory->createServer();
        $transport = new StreamableHttpTransport($request);

        return new McpTransportResponse($server->run($transport));
    }

    protected function createRequestFromGlobals(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequestFromGlobals();
    }

    protected function createUnauthorizedResponse(): ResponseInterface
    {
        return (new Psr17Factory())
            ->createResponse(401)
            ->withHeader('WWW-Authenticate', 'Bearer realm="mcp"');
    }
}
