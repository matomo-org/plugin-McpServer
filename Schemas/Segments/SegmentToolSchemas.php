<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Segments;

final class SegmentToolSchemas
{
    /** Single saved segment with full configuration metadata (matomo_segment_get). */
    public const DETAIL = [
        'type' => 'object',
        'properties' => [
            'idsegment' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'definition' => ['type' => 'string'],
            'idsite' => ['type' => ['integer', 'null']],
            'auto_archive' => ['type' => 'boolean'],
            'enabled_all_users' => ['type' => 'boolean'],
            'login' => ['type' => 'string'],
        ],
        'required' => [
            'idsegment',
            'name',
            'definition',
            'idsite',
            'auto_archive',
            'enabled_all_users',
            'login',
        ],
        'additionalProperties' => false,
    ];

    /** Compact saved-segment row used inside paginated listings (matomo_segment_list). */
    public const SUMMARY = [
        'type' => 'object',
        'properties' => [
            'idsegment' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'definition' => ['type' => 'string'],
            'idsite' => ['type' => ['integer', 'null']],
        ],
        'required' => ['idsegment', 'name', 'definition', 'idsite'],
        'additionalProperties' => false,
    ];

    /** Paginated envelope around SUMMARY rows. */
    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'segments' => [
                'type' => 'array',
                'items' => self::SUMMARY,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
            'total_rows' => ['type' => 'integer'],
        ],
        'required' => ['segments', 'next_cursor', 'has_more', 'total_rows'],
        'additionalProperties' => false,
    ];
}
