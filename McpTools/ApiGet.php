<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @phpstan-import-type ApiMethodSummaryArray from ApiMethodSummaryRecord
 */
class ApiGet
{
    public const TOOL_NAME = 'matomo_api_get';

    public function __construct(
        private ApiMethodSummaryQueryServiceInterface $queryService,
        private SystemSettings $systemSettings,
    ) {
    }

    /**
     * @return ApiMethodSummaryArray
     */
    public function get(?string $method = null, ?string $module = null, ?string $action = null): array
    {
        return $this->queryService->getApiMethodSummaryBySelector(
            $this->systemSettings->getRawApiAccessMode(),
            $method,
            $module,
            $action,
        )->toArray();
    }
}
