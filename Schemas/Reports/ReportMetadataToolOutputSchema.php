<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Reports;

final class ReportMetadataToolOutputSchema
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
            'isSubtableReport' => ['type' => 'boolean'],
            'actionToLoadSubTables' => ['type' => ['string', 'null']],
            'metadata' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
        ],
        'required' => [
            'uniqueId',
            'module',
            'action',
            'name',
            'category',
            'parameters',
            'isSubtableReport',
            'actionToLoadSubTables',
            'metadata',
        ],
        'additionalProperties' => false,
    ];
}
