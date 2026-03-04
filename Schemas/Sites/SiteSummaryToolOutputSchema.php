<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Sites;

final class SiteSummaryToolOutputSchema
{
    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'idsite' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'main_url' => ['type' => 'string'],
            'type' => ['type' => 'string'],
        ],
        'required' => ['idsite', 'name', 'main_url', 'type'],
        'additionalProperties' => false,
    ];

    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'sites' => [
                'type' => 'array',
                'items' => self::ITEM,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
            'total_rows' => ['type' => 'integer'],
        ],
        'required' => ['sites', 'next_cursor', 'has_more', 'total_rows'],
        'additionalProperties' => false,
    ];
}
