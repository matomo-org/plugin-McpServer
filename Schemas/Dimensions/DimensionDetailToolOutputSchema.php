<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Dimensions;

final class DimensionDetailToolOutputSchema
{
    public const EXTRACTION_ITEM = [
        'type' => 'object',
        'properties' => [
            'dimension' => ['type' => 'string'],
            'pattern' => ['type' => 'string'],
        ],
        'required' => ['dimension', 'pattern'],
        'additionalProperties' => false,
    ];

    public const ITEM = [
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
}
