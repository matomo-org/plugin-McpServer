<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Api;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcErrorResponseFactory;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;

/**
 * @group McpServer
 * @group Plugins
 */
class JsonRpcErrorResponseFactoryTest extends TestCase
{
    public function testCreateBuildsJsonRpcErrorResponse(): void
    {
        $factory = new JsonRpcErrorResponseFactory();

        $response = $factory->create(
            401,
            JsonRpcError::INVALID_REQUEST,
            'Authentication required.',
            'request-1',
            ['WWW-Authenticate' => 'Bearer realm="mcp"'],
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('application/json', $response->getHeaderLine('Content-Type'));
        self::assertSame('Bearer realm="mcp"', $response->getHeaderLine('WWW-Authenticate'));

        $error = McpTestHelper::decodeError($response);
        self::assertSame('request-1', $error->id);
        self::assertSame(JsonRpcError::INVALID_REQUEST, $error->code);
        self::assertSame('Authentication required.', $error->message);
    }
}
