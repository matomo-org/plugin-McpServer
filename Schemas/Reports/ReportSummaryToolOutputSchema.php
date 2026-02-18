<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Reports;

use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;

final class ReportSummaryToolOutputSchema
{
    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'uniqueId' => ['type' => 'string'],
            'module' => ['type' => 'string'],
            'action' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'parameters' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
        ],
        'required' => ['uniqueId', 'module', 'action', 'name', 'category', 'parameters'],
        'additionalProperties' => false,
    ];

    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'security' => ToolOutputSecurity::SECURITY_SCHEMA,
            'reports' => [
                'type' => 'array',
                'items' => self::ITEM,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
        ],
        'required' => ['security', 'reports', 'next_cursor', 'has_more'],
        'additionalProperties' => false,
    ];
}
