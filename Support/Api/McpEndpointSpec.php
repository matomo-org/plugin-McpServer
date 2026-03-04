<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

final class McpEndpointSpec
{
    public const MODULE = 'API';
    public const METHOD = 'McpServer.mcp';
    public const FORMAT = 'mcp';

    public const GUARD_ERROR =
        'MCP endpoint requires a root API request: module=API&method=McpServer.mcp&format=mcp. '
        . 'Nested API calls (including API.getBulkRequest) are not supported.';
    public const UNAUTHORIZED_ERROR = 'Authentication required.';
    public const DISABLED_ERROR = 'MCP endpoint is disabled. Please contact your Matomo administrator.';
    public const INTERNAL_ERROR = 'Internal endpoint error.';
}
