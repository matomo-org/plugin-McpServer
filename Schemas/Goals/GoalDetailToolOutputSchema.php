<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Goals;

use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;

final class GoalDetailToolOutputSchema
{
    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'security' => ToolOutputSecurity::SECURITY_SCHEMA,
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
            'security',
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
}
