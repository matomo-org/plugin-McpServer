<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Goals;

final class GoalToolSchemas
{
    /** Single goal with full configuration metadata (matomo_goal_get). */
    public const DETAIL = [
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
            'pattern' => ['type' => ['string', 'null']],
            'pattern_type' => ['type' => ['string', 'null']],
            'case_sensitive' => ['type' => ['boolean', 'null']],
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
            'pattern',
            'pattern_type',
            'case_sensitive',
        ],
        'additionalProperties' => false,
    ];

    /** Compact goal row used inside paginated listings (matomo_goal_list). */
    public const SUMMARY = [
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

    /** Paginated envelope around SUMMARY rows. */
    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'goals' => [
                'type' => 'array',
                'items' => self::SUMMARY,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
            'total_rows' => ['type' => 'integer'],
        ],
        'required' => ['goals', 'next_cursor', 'has_more', 'total_rows'],
        'additionalProperties' => false,
    ];
}
