<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;

class ApiCallDelete extends AbstractApiCall
{
    public const TOOL_NAME = 'matomo_api_call_delete';

    /**
     * @return 'delete'
     */
    protected function getExpectedOperationCategory(): ?string
    {
        return ApiMethodOperationClassifier::CATEGORY_DELETE;
    }
}
