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
use Matomo\Dependencies\McpServer\Mcp\Exception\InvalidCursorException;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\ListPromptsRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\ListPromptsResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
/**
 * @implements RequestHandlerInterface<ListPromptsResult>
 *
 * @author Tobias Nyholm <tobias.nyholm@gmail.com>
 */
final class ListPromptsHandler implements RequestHandlerInterface
{
    public function __construct(private readonly RegistryInterface $registry, private readonly int $pageSize = 20)
    {
    }
    public function supports(Request $request) : bool
    {
        return $request instanceof ListPromptsRequest;
    }
    /**
     * @return Response<ListPromptsResult>
     *
     * @throws InvalidCursorException
     */
    public function handle(Request $request, SessionInterface $session) : Response
    {
        \assert($request instanceof ListPromptsRequest);
        $page = $this->registry->getPrompts($this->pageSize, $request->cursor);
        return new Response($request->getId(), new ListPromptsResult($page->references, $page->nextCursor));
    }
}
