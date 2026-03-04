<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Api;

final class ApiMethodSummaryToolOutputSchema
{
    public const PARAMETER = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'type' => ['type' => ['string', 'null']],
            'required' => ['type' => 'boolean'],
            'allowsNull' => ['type' => 'boolean'],
            'hasDefault' => ['type' => 'boolean'],
            'defaultValue' => [],
        ],
        'required' => ['name', 'type', 'required', 'allowsNull', 'hasDefault', 'defaultValue'],
        'additionalProperties' => false,
    ];

    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'module' => ['type' => 'string'],
            'action' => ['type' => 'string'],
            'method' => ['type' => 'string'],
            'parameters' => [
                'type' => 'array',
                'items' => self::PARAMETER,
            ],
        ],
        'required' => ['module', 'action', 'method', 'parameters'],
        'additionalProperties' => false,
    ];

    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'methods' => [
                'type' => 'array',
                'items' => self::ITEM,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
            'total_rows' => ['type' => 'integer'],
        ],
        'required' => ['methods', 'next_cursor', 'has_more', 'total_rows'],
        'additionalProperties' => false,
    ];
}
