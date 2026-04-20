<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Api;

use Piwik\API\Request;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\CoreApiCallGatewayInterface;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;
use Piwik\Plugins\McpServer\Support\Errors\NoAccessLikeErrorDetector;

final class CoreApiCallGateway implements CoreApiCallGatewayInterface
{
    /** @var callable|null */
    private $requestProcessor;

    public function __construct(?callable $requestProcessor = null)
    {
        $this->requestProcessor = $requestProcessor;
    }

    public function call(string $method, array $parameters): mixed
    {
        try {
            if ($this->requestProcessor !== null) {
                return ($this->requestProcessor)($method, $parameters, []);
            }

            return Request::processRequest($method, $parameters, []);
        } catch (\Throwable $e) {
            if (NoAccessLikeErrorDetector::isDetected($e)) {
                throw new AccessDeniedLikeException('No access to API method.', 0, $e);
            }

            throw new CoreApiRequestException('Matomo API request failed.', 0, $e);
        }
    }
}
