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
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\CoreApiCallGatewayInterface;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;

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
            if ($this->isNoAccessLikeFailure($e)) {
                throw new AccessDeniedLikeException('No access to API method.', 0, $e);
            }

            throw new CoreApiRequestException('Matomo API request failed.', 0, $e);
        }
    }

    private function isNoAccessLikeFailure(\Throwable $e): bool
    {
        if ($e instanceof AccessDeniedLikeException || $e instanceof NoAccessException) {
            return true;
        }

        $message = strtolower(trim((string) $e->getMessage()));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'no access')
            || str_contains($message, 'checkuserhasviewaccess')
            || str_contains($message, 'view access');
    }
}
