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

use Matomo\Dependencies\McpServer\Mcp\Capability\RegistryInterface;
use Matomo\Dependencies\McpServer\Mcp\Exception\ResourceNotFoundException;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ResourceSubscribeRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\EmptyResult;
use Matomo\Dependencies\McpServer\Mcp\Server\RequestContext;
use Matomo\Dependencies\McpServer\Mcp\Server\Resource\SubscriptionManagerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\InvalidArgumentException;
/**
 * @implements RequestHandlerInterface<EmptyResult>
 *
 * @author Larry Sule-balogun <suleabimbola@gmail.com>
 */
final class ResourceSubscribeHandler implements RequestHandlerInterface
{
    public function __construct(private readonly RegistryInterface $registry, private readonly SubscriptionManagerInterface $subscriptionManager, private readonly LoggerInterface $logger = new NullLogger())
    {
    }
    public function supports(Request $request) : bool
    {
        return $request instanceof ResourceSubscribeRequest;
    }
    /**
     * @throws InvalidArgumentException
     */
    public function handle(Request $request, SessionInterface $session) : Response|Error
    {
        \assert($request instanceof ResourceSubscribeRequest);
        $uri = $request->uri;
        try {
            $this->registry->getResource($uri);
        } catch (ResourceNotFoundException $e) {
            $this->logger->error('Resource not found', ['uri' => $uri, 'exception' => $e]);
            return (new RequestContext($session, $request))->getProtocolVersion()->usesInvalidParamsForResourceNotFound() ? Error::forInvalidParams($e->getMessage(), $request->getId(), ['uri' => $uri]) : Error::forResourceNotFound($e->getMessage(), $request->getId());
        }
        $this->logger->debug('Subscribing to resource', ['uri' => $uri]);
        $this->subscriptionManager->subscribe($session, $uri);
        return new Response($request->getId(), new EmptyResult());
    }
}
