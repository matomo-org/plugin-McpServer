<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Api;

use Piwik\Plugins\McpServer\Support\Pagination\ApiMethodsPagination;

final class ApiListToolInputSchema
{
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'limit' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => ApiMethodsPagination::LIMIT_MAX,
                'description' => 'Maximum number of results to return. Uses schema constraints.',
            ],
            'cursor' => [
                'type' => 'string',
                'description' => 'Opaque cursor for pagination.',
            ],
            'sort' => [
                'type' => 'string',
                'enum' => [
                    ApiMethodsPagination::SORT_MODULE_ASC,
                    ApiMethodsPagination::SORT_MODULE_DESC,
                    ApiMethodsPagination::SORT_METHOD_ASC,
                    ApiMethodsPagination::SORT_METHOD_DESC,
                ],
                'description' => 'Sort order for results.',
            ],
            'module' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Optional exact module-name filter (case-insensitive).',
            ],
            'search' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Optional case-insensitive substring filter on the '
                    . 'composite Module.action method name.',
            ],
        ],
        'additionalProperties' => false,
    ];
}
