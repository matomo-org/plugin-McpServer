<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Dimensions;

final class DimensionToolSchemas
{
    /** Nested extraction rule referenced from DETAIL. */
    public const EXTRACTION_ITEM = [
        'type' => 'object',
        'properties' => [
            'dimension' => ['type' => 'string'],
            'pattern' => ['type' => 'string'],
        ],
        'required' => ['dimension', 'pattern'],
        'additionalProperties' => false,
    ];

    /** Single custom dimension with full configuration metadata (matomo_dimension_get). */
    public const DETAIL = [
        'type' => 'object',
        'properties' => [
            'iddimension' => ['type' => 'integer'],
            'idsite' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'index' => ['type' => 'integer'],
            'scope' => ['type' => 'string'],
            'active' => ['type' => 'boolean'],
            'case_sensitive' => ['type' => 'boolean'],
            'extractions' => [
                'type' => 'array',
                'items' => self::EXTRACTION_ITEM,
            ],
        ],
        'required' => [
            'iddimension',
            'idsite',
            'name',
            'index',
            'scope',
            'active',
            'case_sensitive',
            'extractions',
        ],
        'additionalProperties' => false,
    ];

    /** Compact dimension row used inside paginated listings (matomo_dimension_list). */
    public const SUMMARY = [
        'type' => 'object',
        'properties' => [
            'iddimension' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'scope' => ['type' => 'string'],
        ],
        'required' => ['iddimension', 'name', 'scope'],
        'additionalProperties' => false,
    ];

    /** Paginated envelope around SUMMARY rows. */
    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'dimensions' => [
                'type' => 'array',
                'items' => self::SUMMARY,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
            'total_rows' => ['type' => 'integer'],
        ],
        'required' => ['dimensions', 'next_cursor', 'has_more', 'total_rows'],
        'additionalProperties' => false,
    ];
}
