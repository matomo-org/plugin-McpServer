<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request;

use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ReferenceHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ResourceTemplateReference;
use Matomo\Dependencies\McpServer\Mcp\Capability\RegistryInterface;
use Matomo\Dependencies\McpServer\Mcp\Exception\ResourceNotFoundException;
use Matomo\Dependencies\McpServer\Mcp\Exception\ResourceReadException;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ReadResourceRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\ReadResourceResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
/**
 * @implements RequestHandlerInterface<ReadResourceResult>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ReadResourceHandler implements RequestHandlerInterface
{
    public function __construct(private readonly RegistryInterface $referenceProvider, private readonly ReferenceHandlerInterface $referenceHandler, private readonly LoggerInterface $logger = new NullLogger())
    {
    }
    public function supports(Request $request) : bool
    {
        return $request instanceof ReadResourceRequest;
    }
    /**
     * @return Response<ReadResourceResult>|Error
     */
    public function handle(Request $request, SessionInterface $session) : Response|Error
    {
        \assert($request instanceof ReadResourceRequest);
        $uri = $request->uri;
        $this->logger->debug('Reading resource', ['uri' => $uri]);
        try {
            $reference = $this->referenceProvider->getResource($uri);
            $arguments = ['uri' => $uri, '_session' => $session, '_request' => $request];
            if ($reference instanceof ResourceTemplateReference) {
                $variables = $reference->extractVariables($uri);
                $arguments = array_merge($arguments, $variables);
                $result = $this->referenceHandler->handle($reference, $arguments);
                $formatted = $reference->formatResult($result, $uri, $reference->resourceTemplate->mimeType);
            } else {
                $result = $this->referenceHandler->handle($reference, $arguments);
                $formatted = $reference->formatResult($result, $uri, $reference->resource->mimeType);
            }
            return new Response($request->getId(), new ReadResourceResult($formatted));
        } catch (ResourceReadException $e) {
            $this->logger->error(\sprintf('Error while reading resource "%s": "%s".', $uri, $e->getMessage()), ['exception' => $e]);
            return Error::forInternalError($e->getMessage(), $request->getId());
        } catch (ResourceNotFoundException $e) {
            $this->logger->error('Resource not found', ['uri' => $uri, 'exception' => $e]);
            return Error::forResourceNotFound($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Unexpected error while reading resource "%s": "%s".', $uri, $e->getMessage()), ['exception' => $e]);
            return Error::forInternalError('Error while reading resource', $request->getId());
        }
    }
}
