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

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
/**
 * @template TResult
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
interface RequestHandlerInterface
{
    public function supports(Request $request) : bool;
    /**
     * @return Response<TResult>|Error
     */
    public function handle(Request $request, SessionInterface $session) : Response|Error;
}
