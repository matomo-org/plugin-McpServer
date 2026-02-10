<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Goals;

final class GoalSummaryToolOutputSchema
{
    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'idgoal' => ['type' => 'integer'],
            'idsite' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'match_attribute' => ['type' => 'string'],
            'allow_multiple' => ['type' => 'boolean'],
            'revenue' => ['type' => 'string'],
            'event_value_as_revenue' => ['type' => 'boolean'],
        ],
        'required' => [
            'idgoal',
            'idsite',
            'name',
            'description',
            'match_attribute',
            'allow_multiple',
            'revenue',
            'event_value_as_revenue',
        ],
        'additionalProperties' => false,
    ];

    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'goals' => [
                'type' => 'array',
                'items' => self::ITEM,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
        ],
        'required' => ['goals', 'next_cursor', 'has_more'],
        'additionalProperties' => false,
    ];
}
