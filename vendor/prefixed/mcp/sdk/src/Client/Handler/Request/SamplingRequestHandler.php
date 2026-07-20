<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Matomo\Dependencies\McpServer\Mcp\Client\Handler\Request;

use Matomo\Dependencies\McpServer\Mcp\Exception\SamplingException;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CreateSamplingMessageRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CreateSamplingMessageResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
/**
 * Handler for sampling requests from the server.
 *
 * The MCP server may request the client to sample an LLM during tool execution.
 * This handler wraps a user-provided callback that performs the actual LLM call.
 *
 * @implements RequestHandlerInterface<CreateSamplingMessageResult>
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class SamplingRequestHandler implements RequestHandlerInterface
{
    public function __construct(private readonly SamplingCallbackInterface $callback, private readonly LoggerInterface $logger = new NullLogger())
    {
    }
    public function supports(Request $request) : bool
    {
        return $request instanceof CreateSamplingMessageRequest;
    }
    /**
     * @return Response<CreateSamplingMessageResult>|Error
     */
    public function handle(Request $request) : Response|Error
    {
        \assert($request instanceof CreateSamplingMessageRequest);
        try {
            $result = $this->callback->__invoke($request);
            return new Response($request->getId(), $result);
        } catch (SamplingException $e) {
            $this->logger->error('Sampling failed: ' . $e->getMessage(), ['exception' => $e]);
            return Error::forInternalError($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error('Unexpected error during sampling', ['exception' => $e]);
            return Error::forInternalError('Error while sampling LLM', $request->getId());
        }
    }
}
