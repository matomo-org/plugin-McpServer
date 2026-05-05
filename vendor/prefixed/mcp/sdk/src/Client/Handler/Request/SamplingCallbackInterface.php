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

use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CreateSamplingMessageRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CreateSamplingMessageResult;
/**
 * Contract for callbacks used by SamplingRequestHandler.
 *
 * Implementations perform the actual LLM sampling when requested by the server.
 */
interface SamplingCallbackInterface
{
    public function __invoke(CreateSamplingMessageRequest $request) : CreateSamplingMessageResult;
}
