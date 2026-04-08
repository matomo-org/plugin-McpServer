<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Api;

use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiCallRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;

interface ApiCallQueryServiceInterface
{
    /**
     * @param array<string, mixed>|null $parameters
     */
    public function callApi(
        ApiMethodSummaryRecord $resolvedMethod,
        ?array $parameters = null,
    ): ApiCallRecord;
}
