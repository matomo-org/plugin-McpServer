<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Schemas\Api;

final class ApiCallToolOutputSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function item(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                // `new \stdClass()` so `json_encode` emits `{}` (an empty JSON Schema =
                // "any value"); `[]` would encode as `[]` and fail MCP schema validation.
                'result' => new \stdClass(),
                'resolvedMethod' => ApiMethodSummaryToolOutputSchema::item(),
            ],
            'required' => ['result', 'resolvedMethod'],
            'additionalProperties' => false,
        ];
    }
}
