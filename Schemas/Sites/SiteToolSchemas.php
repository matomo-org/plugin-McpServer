<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Sites;

final class SiteToolSchemas
{
    /** Single site with full configuration metadata (matomo_site_get). */
    public const DETAIL = [
        'type' => 'object',
        'properties' => [
            'idsite' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'main_url' => ['type' => 'string'],
            'timezone' => ['type' => 'string'],
            'timezone_name' => ['type' => 'string'],
            'currency' => ['type' => 'string'],
            'currency_name' => ['type' => 'string'],
            'ecommerce' => ['type' => 'boolean'],
            'sitesearch' => ['type' => 'boolean'],
            'type' => ['type' => 'string'],
        ],
        'required' => [
            'idsite',
            'name',
            'main_url',
            'timezone',
            'timezone_name',
            'currency',
            'currency_name',
            'ecommerce',
            'sitesearch',
            'type',
        ],
        'additionalProperties' => false,
    ];

    /** Compact site row used inside paginated listings (matomo_site_list / matomo_site_search). */
    public const SUMMARY = [
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

    /** Paginated envelope around SUMMARY rows. */
    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'sites' => [
                'type' => 'array',
                'items' => self::SUMMARY,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
            'total_rows' => ['type' => 'integer'],
        ],
        'required' => ['sites', 'next_cursor', 'has_more', 'total_rows'],
        'additionalProperties' => false,
    ];
}
