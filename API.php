<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Piwik\API\Request as ApiRequest;
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
        $requestParams = Request::fromRequest();
        $format = strtolower($requestParams->getStringParameter('format', ''));
        $module = $requestParams->getStringParameter('module', '');
        $method = $requestParams->getStringParameter('method', '');
        $isRootApiRequest = $this->isCurrentApiRequestRoot();
        $rootApiMethod = $this->getRootApiRequestMethod();

        if (
            $format !== 'mcp'
            || $module !== 'API'
            || $method !== 'McpServer.mcp'
            || !$isRootApiRequest
            || $rootApiMethod !== 'McpServer.mcp'
        ) {
            throw new BadRequestException(
                'MCP endpoint requires a root API request: module=API&method=McpServer.mcp&format=mcp. '
                . 'Nested API calls (including API.getBulkRequest) are not supported.'
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

    protected function isCurrentApiRequestRoot(): bool
    {
        return ApiRequest::isCurrentApiRequestTheRootApiRequest();
    }

    protected function getRootApiRequestMethod(): string
    {
        return (string) ApiRequest::getRootApiRequestMethod();
    }
}
