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
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Server\Transport\StreamableHttpTransport;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\API\Request as ApiRequest;
use Piwik\Http\BadRequestException;
use Piwik\NoAccessException;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcErrorResponseFactory;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcRequestIdExtractor;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointGuard;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointSpec;
use Piwik\Request;

class API extends \Piwik\Plugin\API
{
    public function __construct(
        private McpServerFactory $factory,
        private McpEndpointGuard $endpointGuard,
        private JsonRpcErrorResponseFactory $jsonRpcErrorResponseFactory,
        private JsonRpcRequestIdExtractor $jsonRpcRequestIdExtractor,
        private SystemSettings $systemSettings,
    ) {
    }

    /**
     * @internal
     */
    public function mcp(): ResponseInterface
    {
        $requestParams = Request::fromRequest();
        $format = strtolower($requestParams->getStringParameter('format', ''));
        $errorFactory = $this->jsonRpcErrorResponseFactory;

        // If format!=mcp, Matomo will use a non-MCP renderer that cannot serialize PSR-7 responses.
        if ($format !== McpEndpointSpec::FORMAT) {
            throw new BadRequestException(
                'MCP endpoint requires format=mcp. Use module=API&method=McpServer.mcp&format=mcp.',
            );
        }

        try {
            $request = $this->createRequestFromGlobals();
        } catch (\Throwable $e) {
            return $errorFactory->create(
                500,
                JsonRpcError::INTERNAL_ERROR,
                McpEndpointSpec::INTERNAL_ERROR,
            );
        }

        $requestMetadata = $this->jsonRpcRequestIdExtractor->extractRequestMetadata($request);
        $requestId = $requestMetadata['requestId'];
        $guardError = $this->endpointGuard->validate(
            $format,
            $requestParams->getStringParameter('module', ''),
            $requestParams->getStringParameter('method', ''),
            $this->isCurrentApiRequestRoot(),
            $this->getRootApiRequestMethod(),
        );

        if ($guardError !== null) {
            return $errorFactory->create(400, JsonRpcError::INVALID_REQUEST, $guardError, $requestId);
        }

        try {
            $this->checkUserHasSomeViewAccess();
        } catch (\Throwable $e) {
            if ($this->isUnauthorizedLike($e)) {
                return $this->createUnauthorizedResponse($requestId);
            }

            return $errorFactory->create(
                500,
                JsonRpcError::INTERNAL_ERROR,
                McpEndpointSpec::INTERNAL_ERROR,
                $requestId,
            );
        }

        if (!$this->isMcpEnabled()) {
            return $this->createDisabledResponse($requestMetadata['topLevelRequestId']);
        }

        try {
            $server = $this->factory->createServer();
            $transport = new StreamableHttpTransport($request);

            return $server->run($transport);
        } catch (\Throwable) {
            return $errorFactory->create(
                500,
                JsonRpcError::INTERNAL_ERROR,
                McpEndpointSpec::INTERNAL_ERROR,
                $requestId,
            );
        }
    }

    protected function createRequestFromGlobals(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequestFromGlobals();
    }

    protected function checkUserHasSomeViewAccess(): void
    {
        Piwik::checkUserHasSomeViewAccess();
    }

    protected function createUnauthorizedResponse(string|int $requestId = ''): ResponseInterface
    {
        return $this->jsonRpcErrorResponseFactory->create(
            401,
            JsonRpcError::INVALID_REQUEST,
            McpEndpointSpec::UNAUTHORIZED_ERROR,
            $requestId,
            ['WWW-Authenticate' => 'Bearer realm="mcp"'],
        );
    }

    protected function isMcpEnabled(): bool
    {
        return $this->systemSettings->isMcpEnabled();
    }

    protected function isCurrentApiRequestRoot(): bool
    {
        return ApiRequest::isCurrentApiRequestTheRootApiRequest();
    }

    protected function getRootApiRequestMethod(): string
    {
        return (string) ApiRequest::getRootApiRequestMethod();
    }

    private function isUnauthorizedLike(\Throwable $e): bool
    {
        $current = $e;

        do {
            if ($current instanceof NoAccessException) {
                return true;
            }

            $current = $current->getPrevious();
        } while ($current !== null);

        return false;
    }

    protected function createDisabledResponse(string|int|null $topLevelRequestId): ResponseInterface
    {
        if ($topLevelRequestId === null) {
            return (new Psr17Factory())->createResponse(403);
        }

        return $this->jsonRpcErrorResponseFactory->create(
            403,
            JsonRpcError::INVALID_REQUEST,
            McpEndpointSpec::DISABLED_ERROR,
            $topLevelRequestId,
        );
    }
}
