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
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Piwik\API\Request as ApiRequest;
use Piwik\Http\BadRequestException;
use Piwik\Log\LoggerInterface;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Support\Access\McpAccessGate;
use Piwik\Plugins\McpServer\Support\Access\McpUnavailableException;
use Piwik\Plugins\McpServer\Support\Api\InternalApiAccessGuard;
use Piwik\Plugins\McpServer\Support\Api\InternalToolCaller;
use Piwik\Plugins\McpServer\Support\Api\InternalToolCatalog;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcErrorResponseFactory;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcRequestIdExtractor;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointGuard;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointSpec;
use Piwik\Plugins\McpServer\Support\Api\SessionEndEventPublisher;
use Piwik\Request;

class API extends \Piwik\Plugin\API
{
    public function __construct(
        private McpServerFactory $factory,
        private McpEndpointGuard $endpointGuard,
        private JsonRpcErrorResponseFactory $jsonRpcErrorResponseFactory,
        private JsonRpcRequestIdExtractor $jsonRpcRequestIdExtractor,
        private McpAccessGate $accessGate,
        private InternalApiAccessGuard $internalAccessGuard,
        private InternalToolCatalog $internalToolCatalog,
        private InternalToolCaller $internalToolCaller,
        private LoggerInterface $logger,
        private SessionEndEventPublisher $sessionEndEventPublisher,
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
            // The 500 the client receives is deliberately opaque, so log the
            // real cause to keep the failure diagnosable.
            $this->logger->error('MCP request could not be built from the incoming HTTP request: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

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
            $this->accessGate->assertHttp();
        } catch (McpUnavailableException | BadRequestException $e) {
            return $this->createForbiddenResponse($requestMetadata['topLevelRequestId'], $e->getMessage());
        } catch (\Throwable $e) {
            if ($this->isUnauthorizedLike($e)) {
                return $this->createUnauthorizedResponse($requestId);
            }

            // Expected rejections (MCP disabled, privilege ceiling, unauthorized)
            // are handled above; anything reaching here is an unexpected failure,
            // so log the real cause behind the opaque 500.
            $this->logger->error('MCP access check failed unexpectedly: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $errorFactory->create(
                500,
                JsonRpcError::INTERNAL_ERROR,
                McpEndpointSpec::INTERNAL_ERROR,
                $requestId,
            );
        }

        try {
            $server = $this->factory->createServer();
            $transport = McpServerFactory::createTransport($request);
            $sessionId = $this->sessionEndEventPublisher->captureExistingSessionOnDelete($request);

            $response = $server->run($transport);
            $this->sessionEndEventPublisher->publishIfSessionEnded($sessionId, $response);

            return $response;
        } catch (\Throwable $e) {
            // The 500 the client receives is deliberately opaque, so log the
            // real cause (for example a plugin contributing a broken tool, or
            // an unresolvable built-in) to make the failure diagnosable.
            $this->logger->error('MCP server could not be built or run: {message}', [
                'message' => $e->getMessage(),
                'exception' => $e,
            ]);

            return $errorFactory->create(
                500,
                JsonRpcError::INTERNAL_ERROR,
                McpEndpointSpec::INTERNAL_ERROR,
                $requestId,
            );
        }
    }

    /**
     * Returns the registered MCP tool catalogue for in-process consumers.
     *
     * Throws {@see \Piwik\NoAccessException} when the caller is anonymous or
     * lacks view access, and {@see McpUnavailableException} when MCP is
     * disabled site-wide; consumers can branch on the latter to surface a
     * "feature off" message instead of a generic access error.
     *
     * @internal
     * @return list<array{
     *     name: string,
     *     title: string|null,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     outputSchema: array<string, mixed>|null,
     *     readOnly: bool|null,
     *     destructive: bool|null,
     *     idempotent: bool|null,
     *     openWorld: bool|null
     * }> Registered MCP tools with schemas and behaviour hints.
     */
    public function getInternalToolCatalog(): array
    {
        $this->internalAccessGuard->assertInternalContext();
        $this->accessGate->assertBase();

        return $this->internalToolCatalog->build($this->factory->createInternalAccess());
    }

    /**
     * Calls a registered MCP tool for in-process consumers.
     *
     * Tool and protocol failures are returned as an `isError` payload; context
     * and access failures throw as ordinary Matomo API exceptions
     * ({@see \Piwik\NoAccessException} for anonymous / no-view callers,
     * {@see McpUnavailableException} when MCP is disabled site-wide).
     *
     * @internal
     * @unsanitized
     * @param string $name Registered MCP tool name.
     * @param array<string, mixed> $arguments Arguments validated against the tool input schema.
     * @param string|null $sessionKey Optional logical session key for grouping related internal calls.
     * @return array{
     *     content: list<array<string, mixed>>,
     *     structuredContent: array<string, mixed>|null,
     *     isError: bool
     * } Serialized MCP tool result payload.
     */
    public function callInternalTool(string $name, array $arguments = [], ?string $sessionKey = null): array
    {
        $this->internalAccessGuard->assertInternalContext();
        $this->accessGate->assertBase();

        return $this->internalToolCaller->call(
            $this->factory->createInternalAccess(),
            $name,
            $arguments,
            $sessionKey,
        );
    }

    protected function createRequestFromGlobals(): ServerRequestInterface
    {
        return (new Psr17Factory())->createServerRequestFromGlobals();
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

    /**
     * Shape a 403 response for the HTTP mcp() path when {@see McpAccessGate::assertHttp()}
     * rejected the request via {@see McpUnavailableException} (MCP disabled) or
     * {@see BadRequestException} (privilege ceiling exceeded). The exception's
     * message is the canonical text.
     */
    protected function createForbiddenResponse(string|int|null $topLevelRequestId, string $message): ResponseInterface
    {
        if ($topLevelRequestId === null) {
            return (new Psr17Factory())->createResponse(403);
        }

        return $this->jsonRpcErrorResponseFactory->create(
            403,
            JsonRpcError::INVALID_REQUEST,
            $message,
            $topLevelRequestId,
        );
    }
}
