<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Renderer;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use Piwik\Common;
use Piwik\Http\BadRequestException;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointSpec;

class Mcp extends \Piwik\API\ApiRenderer
{
    /**
     * @return void
     */
    protected function init()
    {
        parent::init();

        $method = $this->requestObj->getStringParameter('method', '');
        if ($method !== 'McpServer.mcp') {
            throw new BadRequestException('format=mcp can only be used with method=McpServer.mcp.');
        }
    }

    /**
     * @return void
     */
    public function sendHeader()
    {
    }

    /**
     * @param mixed $object
     * @return mixed
     */
    public function renderObject($object)
    {
        if (!$object instanceof ResponseInterface) {
            throw new BadRequestException('MCP formatter expects a PSR-7 response payload.');
        }

        $response = $object;
        $this->applyStatusCode($response->getStatusCode());
        $this->sendResponseHeaders($response);

        return $this->extractBody($response);
    }

    /**
     * @param mixed $message
     * @return mixed
     */
    public function renderSuccess($message)
    {
        $this->rejectUnsupported('scalar success');
    }

    /**
     * @param array<mixed> $array
     * @return mixed
     */
    public function renderArray($array)
    {
        $this->rejectUnsupported('array');
    }

    /**
     * @param mixed $scalar
     * @return mixed
     */
    public function renderScalar($scalar)
    {
        $this->rejectUnsupported('scalar');
    }

    /**
     * @param mixed $dataTable
     * @return mixed
     */
    public function renderDataTable($dataTable)
    {
        $this->rejectUnsupported('DataTable');
    }

    /**
     * @param mixed $message
     * @param \Exception|\Throwable $exception
     * @return string
     */
    public function renderException($message, $exception)
    {
        $this->sendHeaderLine('Content-Type: application/json', true);

        $errorCode = $exception instanceof BadRequestException
            ? JsonRpcError::INVALID_REQUEST
            : JsonRpcError::INTERNAL_ERROR;
        $errorMessage = $this->normalizeErrorMessage($message, $errorCode);

        $payload = json_encode(
            new JsonRpcError('', $errorCode, $errorMessage),
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES,
        );

        if (!is_string($payload)) {
            return '{"jsonrpc":"2.0","id":"","error":{"code":-32603,"message":"Internal endpoint error."}}';
        }

        return $payload;
    }

    protected function applyStatusCode(int $statusCode): void
    {
        http_response_code($statusCode);
    }

    protected function sendResponseHeaders(ResponseInterface $response): void
    {
        foreach ($response->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $this->sendHeaderLine($name . ': ' . $value, false);
            }
        }
    }

    protected function sendHeaderLine(string $header, bool $replace): void
    {
        Common::sendHeader($header, $replace);
    }

    protected function extractBody(ResponseInterface $response): string
    {
        $body = $response->getBody();
        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->getContents();
    }

    /**
     * @return never
     */
    private function rejectUnsupported(string $payloadKind): void
    {
        throw new BadRequestException(sprintf('MCP formatter cannot render %s responses.', $payloadKind));
    }

    /**
     * @param mixed $message
     */
    private function normalizeErrorMessage($message, int $errorCode): string
    {
        if (is_string($message) && $message !== '') {
            return $message;
        }

        if (is_scalar($message)) {
            return (string) $message;
        }

        if ($errorCode === JsonRpcError::INVALID_REQUEST) {
            return 'Invalid request.';
        }

        return McpEndpointSpec::INTERNAL_ERROR;
    }
}
