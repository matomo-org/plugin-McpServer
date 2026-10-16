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
use Matomo\Dependencies\McpServer\Mcp\Capability\RegistryInterface;
use Matomo\Dependencies\McpServer\Mcp\Exception\MissingRequiredClientCapabilityException;
use Matomo\Dependencies\McpServer\Mcp\Exception\PromptGetException;
use Matomo\Dependencies\McpServer\Mcp\Exception\PromptNotFoundException;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\GetPromptRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\GetPromptResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\InputRequiredResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
/**
 * @implements RequestHandlerInterface<GetPromptResult|InputRequiredResult>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class GetPromptHandler implements RequestHandlerInterface
{
    public function __construct(private readonly RegistryInterface $registry, private readonly ReferenceHandlerInterface $referenceHandler, private readonly LoggerInterface $logger = new NullLogger())
    {
    }
    public function supports(Request $request) : bool
    {
        return $request instanceof GetPromptRequest;
    }
    /**
     * @return Response<GetPromptResult|InputRequiredResult>|Error
     */
    public function handle(Request $request, SessionInterface $session) : Response|Error
    {
        \assert($request instanceof GetPromptRequest);
        $promptName = $request->name;
        $arguments = $request->arguments ?? [];
        try {
            $reference = $this->registry->getPrompt($promptName);
            $arguments['_session'] = $session;
            $arguments['_request'] = $request;
            $result = $this->referenceHandler->handle($reference, $arguments);
            // An ask is a result in its own right, not prompt content.
            if ($result instanceof InputRequiredResult) {
                return new Response($request->getId(), $result);
            }
            $formatted = $reference->formatResult($result);
            return new Response($request->getId(), new GetPromptResult($formatted));
        } catch (MissingRequiredClientCapabilityException $e) {
            // Not a handler failure — the request was unservable, and the client
            // needs to retry declaring the capability. Rendered as -32021.
            throw $e;
        } catch (PromptGetException $e) {
            $this->logger->error(\sprintf('Error while handling prompt "%s": "%s".', $promptName, $e->getMessage()), ['exception' => $e]);
            return Error::forInternalError($e->getMessage(), $request->getId());
        } catch (PromptNotFoundException $e) {
            $this->logger->error('Prompt not found', ['prompt_name' => $promptName, 'exception' => $e]);
            // An unknown prompt name is a bad parameter, not a missing
            // resource: -32002 was never the code for this.
            return Error::forInvalidParams($e->getMessage(), $request->getId());
        } catch (\Throwable $e) {
            $this->logger->error(\sprintf('Unexpected error while handling prompt "%s": "%s".', $promptName, $e->getMessage()), ['exception' => $e]);
            return Error::forInternalError('Error while handling prompt', $request->getId());
        }
    }
}
