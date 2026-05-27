<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Api;

use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;

final class ApiMethodSummaryToolOutputSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function parameter(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => ['type' => 'string'],
                'type' => ['type' => ['string', 'null']],
                'required' => ['type' => 'boolean'],
                'allowsNull' => ['type' => 'boolean'],
                'hasDefault' => ['type' => 'boolean'],
                // `new \stdClass()` so `json_encode` emits `{}` (an empty JSON Schema =
                // "any value"); `[]` would encode as `[]` and fail MCP schema validation.
                'defaultValue' => new \stdClass(),
            ],
            'required' => ['name', 'type', 'required', 'allowsNull', 'hasDefault', 'defaultValue'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function item(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'module' => ['type' => 'string'],
                'action' => ['type' => 'string'],
                'method' => ['type' => 'string'],
                'parameters' => [
                    'type' => 'array',
                    'items' => self::parameter(),
                ],
                'operationCategory' => [
                    'type' => ['string', 'null'],
                    'enum' => [
                        ApiMethodOperationClassifier::CATEGORY_READ,
                        ApiMethodOperationClassifier::CATEGORY_CREATE,
                        ApiMethodOperationClassifier::CATEGORY_UPDATE,
                        ApiMethodOperationClassifier::CATEGORY_DELETE,
                        null,
                    ],
                ],
            ],
            'required' => [
                'module',
                'action',
                'method',
                'parameters',
                'operationCategory',
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function paginatedList(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'methods' => [
                    'type' => 'array',
                    'items' => self::item(),
                ],
                'next_cursor' => ['type' => ['string', 'null']],
                'has_more' => ['type' => 'boolean'],
                'total_rows' => ['type' => 'integer'],
            ],
            'required' => ['methods', 'next_cursor', 'has_more', 'total_rows'],
            'additionalProperties' => false,
        ];
    }
}
