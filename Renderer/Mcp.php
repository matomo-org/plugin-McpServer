<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Renderer;

use Matomo\Dependencies\McpServer\Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Piwik\Http\BadRequestException;
use Piwik\Plugins\McpServer\Support\Api\McpTransportResponse;

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
        if (!$object instanceof McpTransportResponse) {
            throw new BadRequestException('MCP formatter expects a McpTransportResponse payload.');
        }

        $this->emit($object->response());
        $this->terminate();
    }

    /**
     * @param mixed $message
     * @return mixed
     */
    public function renderSuccess($message)
    {
        throw new BadRequestException('MCP formatter cannot render scalar success responses.');
    }

    /**
     * @param array<mixed> $array
     * @return mixed
     */
    public function renderArray($array)
    {
        throw new BadRequestException('MCP formatter cannot render array responses.');
    }

    /**
     * @param mixed $scalar
     * @return mixed
     */
    public function renderScalar($scalar)
    {
        throw new BadRequestException('MCP formatter cannot render scalar responses.');
    }

    /**
     * @param mixed $dataTable
     * @return mixed
     */
    public function renderDataTable($dataTable)
    {
        throw new BadRequestException('MCP formatter cannot render DataTable responses.');
    }

    /**
     * @param mixed $message
     * @param \Exception|\Throwable $exception
     * @return string
     */
    public function renderException($message, $exception)
    {
        if (is_string($message)) {
            return $message;
        }

        if (is_scalar($message)) {
            return (string) $message;
        }

        return 'MCP formatter error.';
    }

    protected function emit(\Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface $response): void
    {
        (new SapiEmitter())->emit($response);
    }

    protected function terminate(): void
    {
        exit;
    }
}
