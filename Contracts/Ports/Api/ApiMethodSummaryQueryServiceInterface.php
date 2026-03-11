<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Api;

use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryQueryRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;

interface ApiMethodSummaryQueryServiceInterface
{
    /**
     * @return array<int, ApiMethodSummaryRecord>
     */
    public function getApiMethodSummaries(ApiMethodSummaryQueryRecord $query): array;

    public function getApiMethodSummaryBySelector(
        string $accessMode,
        ?string $method = null,
        ?string $module = null,
        ?string $action = null,
    ): ApiMethodSummaryRecord;
}
