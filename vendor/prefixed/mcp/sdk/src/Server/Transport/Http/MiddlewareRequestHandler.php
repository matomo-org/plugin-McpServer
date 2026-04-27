<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Matomo\Dependencies\McpServer\Mcp\Server\Transport\Http;

use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Server\MiddlewareInterface;
use Matomo\Dependencies\McpServer\Psr\Http\Server\RequestHandlerInterface;
/**
 * A request handler that processes a middleware pipeline before dispatching
 * the request to the core transport handler.
 *
 * @author Volodymyr Panivko <sveneld300@gmail.com>
 *
 * @internal
 */
final class MiddlewareRequestHandler implements RequestHandlerInterface
{
    private int $index = 0;
    /**
     * @param list<MiddlewareInterface> $middleware
     */
    public function __construct(private readonly array $middleware, private readonly \Closure $application)
    {
    }
    public function handle(ServerRequestInterface $request) : ResponseInterface
    {
        if (!isset($this->middleware[$this->index])) {
            return ($this->application)($request);
        }
        return $this->middleware[$this->index++]->process($request, $this);
    }
}
