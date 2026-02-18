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

final class ReportMetadataToolOutputSchema
{
    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'security' => ToolOutputSecurity::SECURITY_SCHEMA,
            'uniqueId' => ['type' => 'string'],
            'module' => ['type' => 'string'],
            'action' => ['type' => 'string'],
            'name' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'parameters' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
            'metadata' => [
                'type' => 'object',
                'additionalProperties' => true,
            ],
        ],
        'required' => ['security', 'uniqueId', 'module', 'action', 'name', 'category', 'parameters', 'metadata'],
        'additionalProperties' => false,
    ];
}
