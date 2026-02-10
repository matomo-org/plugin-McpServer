<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Segments;

final class SegmentSummaryToolOutputSchema
{
    public const ITEM = [
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

    public const PAGINATED_LIST = [
        'type' => 'object',
        'properties' => [
            'segments' => [
                'type' => 'array',
                'items' => self::ITEM,
            ],
            'next_cursor' => ['type' => ['string', 'null']],
            'has_more' => ['type' => 'boolean'],
        ],
        'required' => ['segments', 'next_cursor', 'has_more'],
        'additionalProperties' => false,
    ];
}
