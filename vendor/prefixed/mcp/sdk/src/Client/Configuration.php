<?php

/*
 * This file is part of the official PHP MCP SDK.
 *
 * A collaboration between Symfony and the PHP Foundation.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Matomo\Dependencies\McpServer\Mcp\Client;

use Matomo\Dependencies\McpServer\Mcp\Schema\ClientCapabilities;
use Matomo\Dependencies\McpServer\Mcp\Schema\Enum\ProtocolVersion;
use Matomo\Dependencies\McpServer\Mcp\Schema\Implementation;
/**
 * Client configuration holder.
 *
 * @author Kyrian Obikwelu <koshnawaza@gmail.com>
 */
class Configuration
{
    public function __construct(public readonly Implementation $clientInfo, public readonly ClientCapabilities $capabilities, public readonly ProtocolVersion $protocolVersion = ProtocolVersion::V2025_06_18, public readonly int $initTimeout = 30, public readonly int $requestTimeout = 120, public readonly int $maxRetries = 3)
    {
    }
}
