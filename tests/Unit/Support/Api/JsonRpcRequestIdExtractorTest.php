<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Api;

use Matomo\Dependencies\McpServer\Http\Discovery\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Api\JsonRpcRequestIdExtractor;

/**
 * @group McpServer
 * @group Plugins
 */
class JsonRpcRequestIdExtractorTest extends TestCase
{
    public function testExtractsIdFromSingleMessage(): void
    {
        $extractor = new JsonRpcRequestIdExtractor();
        $request = $this->createRequest('{"jsonrpc":"2.0","id":"abc","method":"ping"}');

        self::assertSame('abc', $extractor->extractId($request));
    }

    public function testExtractsIdFromBatchMessage(): void
    {
        $extractor = new JsonRpcRequestIdExtractor();
        $request = $this->createRequest('[{"jsonrpc":"2.0","id":5,"method":"ping"}]');

        self::assertSame(5, $extractor->extractId($request));
    }

    public function testReturnsEmptyIdForInvalidJson(): void
    {
        $extractor = new JsonRpcRequestIdExtractor();
        $request = $this->createRequest('{invalid');

        self::assertSame('', $extractor->extractId($request));
    }

    private function createRequest(string $body): \Matomo\Dependencies\McpServer\Psr\Http\Message\ServerRequestInterface
    {
        $factory = new Psr17Factory();

        return $factory
            ->createServerRequest('POST', 'https://example.test/mcp')
            ->withBody($factory->createStream($body));
    }
}
