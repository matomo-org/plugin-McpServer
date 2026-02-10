<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Sites;

final class SiteDetailToolOutputSchema
{
    public const ITEM = [
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
}
