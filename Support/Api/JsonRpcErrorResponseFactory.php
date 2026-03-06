<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

use Matomo\Dependencies\McpServer\Http\Discovery\Psr17Factory;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;

final class JsonRpcErrorResponseFactory
{
    /**
     * @param array<string, string> $headers
     */
    public function create(
        int $httpStatus,
        int $jsonRpcCode,
        string $message,
        string|int $id = '',
        array $headers = []
    ): ResponseInterface {
        $payload = $this->encodeError($id, $jsonRpcCode, $message);

        $psr17 = new Psr17Factory();
        $response = $psr17
            ->createResponse($httpStatus)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($psr17->createStream($payload));

        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }

    private function encodeError(string|int $id, int $code, string $message): string
    {
        $json = json_encode(
            new JsonRpcError($id, $code, $message),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES
        );

        if (!is_string($json)) {
            return '{"jsonrpc":"2.0","id":"","error":{"code":-32603,"message":"Internal endpoint error."}}';
        }

        return $json;
    }
}
