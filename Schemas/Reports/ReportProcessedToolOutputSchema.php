<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Reports;

final class ReportProcessedToolOutputSchema
{
    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'report' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
            'pagination' => [
                'type' => 'object',
                'properties' => [
                    'filter_limit' => ['type' => 'integer'],
                    'filter_offset' => ['type' => 'integer'],
                    'returned_rows' => ['type' => 'integer'],
                    'has_more' => ['type' => 'boolean'],
                ],
                'required' => ['filter_limit', 'filter_offset', 'returned_rows', 'has_more'],
                'additionalProperties' => false,
            ],
            'resolvedReport' => [
                'type' => 'object',
                'properties' => [
                    'uniqueId' => ['type' => 'string'],
                    'apiModule' => ['type' => 'string'],
                    'apiAction' => ['type' => 'string'],
                    'apiParameters' => [
                        'type' => 'object',
                        'additionalProperties' => true,
                    ],
                ],
                'required' => ['uniqueId', 'apiModule', 'apiAction', 'apiParameters'],
                'additionalProperties' => false,
            ],
        ],
        'required' => ['report', 'pagination', 'resolvedReport'],
        'additionalProperties' => false,
    ];
}
