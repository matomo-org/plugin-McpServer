<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;

final class McpTransportResponse
{
    public function __construct(private ResponseInterface $response)
    {
    }

    public function response(): ResponseInterface
    {
        return $this->response;
    }
}
