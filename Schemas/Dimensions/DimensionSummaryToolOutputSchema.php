<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Dimensions;

final class DimensionSummaryToolOutputSchema
{
    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'iddimension' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'scope' => ['type' => 'string'],
        ],
        'required' => ['iddimension', 'name', 'scope'],
        'additionalProperties' => false,
    ];

    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'dimensions' => [
                'type' => 'array',
                'items' => self::ITEM,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
        ],
        'required' => ['dimensions', 'next_cursor', 'has_more'],
        'additionalProperties' => false,
    ];
}
