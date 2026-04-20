<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Api;

final class ApiCallToolOutputSchema
{
    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'result' => [],
            'resolvedMethod' => ApiMethodSummaryToolOutputSchema::ITEM,
        ],
        'required' => ['result', 'resolvedMethod'],
        'additionalProperties' => false,
    ];
}
