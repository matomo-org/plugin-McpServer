<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Api;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointGuard;

/**
 * @group McpServer
 * @group Plugins
 */
class McpEndpointGuardTest extends TestCase
{
    public function testValidateReturnsNullForValidEndpoint(): void
    {
        $guard = new McpEndpointGuard();

        self::assertNull($guard->validate('mcp', 'API', 'McpServer.mcp', true, 'McpServer.mcp'));
    }

    public function testValidateReturnsErrorForInvalidEndpoint(): void
    {
        $guard = new McpEndpointGuard();

        $message = $guard->validate('json', 'API', 'McpServer.mcp', true, 'McpServer.mcp');

        self::assertIsString($message);
        self::assertStringStartsWith('MCP endpoint requires a root API request:', $message);
    }
}
