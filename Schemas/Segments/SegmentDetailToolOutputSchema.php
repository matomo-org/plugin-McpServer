<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Segments;

use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;

final class SegmentDetailToolOutputSchema
{
    public const ITEM = [
        'type' => 'object',
        'properties' => [
            'security' => ToolOutputSecurity::SECURITY_SCHEMA,
            'idsegment' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'definition' => ['type' => 'string'],
            'idsite' => ['type' => ['integer', 'null']],
            'auto_archive' => ['type' => 'boolean'],
            'enabled_all_users' => ['type' => 'boolean'],
            'login' => ['type' => 'string'],
        ],
        'required' => [
            'security',
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
}
