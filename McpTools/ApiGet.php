<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Piwik\Config;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;

/**
 * @phpstan-import-type ApiMethodSummaryArray from ApiMethodSummaryRecord
 */
class ApiGet
{
    public const TOOL_NAME = 'matomo_api_get';

    public function __construct(private ApiMethodSummaryQueryServiceInterface $queryService)
    {
    }

    /**
     * @return ApiMethodSummaryArray
     */
    public function get(?string $method = null, ?string $module = null, ?string $action = null): array
    {
        return $this->queryService->getApiMethodSummaryBySelector(
            RawApiAccessMode::normalize(Config::getInstance()->McpServer['raw_api_access_mode'] ?? null),
            $method,
            $module,
            $action,
        )->toArray();
    }
}
