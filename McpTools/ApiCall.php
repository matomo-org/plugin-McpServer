<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiCallQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiCallRecord;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @phpstan-import-type ApiCallArray from ApiCallRecord
 */
class ApiCall
{
    public const TOOL_NAME = 'matomo_api_call';

    public function __construct(
        private ApiCallQueryServiceInterface $queryService,
        private SystemSettings $systemSettings,
    ) {
    }

    /**
     * @param array<string, mixed>|null $parameters
     * @return ApiCallArray
     */
    public function call(
        ?string $method = null,
        ?string $module = null,
        ?string $action = null,
        ?array $parameters = null,
    ): array {
        return $this->queryService->callApi(
            $this->systemSettings->getRawApiAccessMode(),
            $method,
            $module,
            $action,
            $parameters,
        )->toArray();
    }
}
