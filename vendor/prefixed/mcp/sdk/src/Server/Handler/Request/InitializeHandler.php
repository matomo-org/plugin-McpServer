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

use Matomo\Dependencies\McpServer\Mcp\Schema\Implementation;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\InitializeRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\InitializeResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\ServerCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Server\Configuration;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
/**
 * @implements RequestHandlerInterface<InitializeResult>
 *
 * @author Christopher Hertel <mail@christopher-hertel.de>
 */
final class InitializeHandler implements RequestHandlerInterface
{
    public function __construct(public readonly ?Configuration $configuration = null)
    {
    }
    public function supports(Request $request) : bool
    {
        return $request instanceof InitializeRequest;
    }
    /**
     * @return Response<InitializeResult>
     */
    public function handle(Request $request, SessionInterface $session) : Response
    {
        \assert($request instanceof InitializeRequest);
        $session->set('client_info', $request->clientInfo->jsonSerialize());
        return new Response($request->getId(), new InitializeResult($this->configuration->capabilities ?? new ServerCapabilities(), $this->configuration->serverInfo ?? new Implementation(), $this->configuration?->instructions, null, $this->configuration?->protocolVersion));
    }
}
