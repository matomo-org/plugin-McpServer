<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Framework;

/**
 * Returns a JSON metaschema for validating MCP tool inputSchema and outputSchema.
 *
 * Encodes the constraints from the canonical MCP schema definition of
 * `Tool.inputSchema` / `Tool.outputSchema` (see
 * https://github.com/modelcontextprotocol/modelcontextprotocol/blob/main/schema/2025-11-25/schema.json)
 * and adds a recursive requirement that every nested subschema entry under
 * `properties`, `items`, `additionalProperties`, `oneOf`, `anyOf`, `allOf`,
 * or `not` is itself a JSON object. The recursive part catches the common
 * PHP pitfall of writing `'someProp' => []` where an empty JSON Schema is
 * intended: that serializes as JSON `[]` rather than `{}`, which fails the
 * MCP constraint that every schema value is a JSON object.
 */
final class McpToolSchemaMetaschema
{
    /**
     * @return array<string, mixed>
     */
    public static function get(): array
    {
        // Shared between the top-level metaschema and the recursive `jsonSchemaObject` def.
        $schemaBearingProperties = [
            'properties' => [
                'type' => 'object',
                'additionalProperties' => ['$ref' => '#/$defs/jsonSchemaObject'],
            ],
            'items' => ['$ref' => '#/$defs/jsonSchemaObject'],
            'additionalProperties' => [
                'anyOf' => [
                    ['type' => 'boolean'],
                    ['$ref' => '#/$defs/jsonSchemaObject'],
                ],
            ],
            'oneOf' => [
                'type' => 'array',
                'items' => ['$ref' => '#/$defs/jsonSchemaObject'],
            ],
            'anyOf' => [
                'type' => 'array',
                'items' => ['$ref' => '#/$defs/jsonSchemaObject'],
            ],
            'allOf' => [
                'type' => 'array',
                'items' => ['$ref' => '#/$defs/jsonSchemaObject'],
            ],
            'not' => ['$ref' => '#/$defs/jsonSchemaObject'],
        ];

        $jsonSchemaObject = [
            'type' => 'object',
            'properties' => $schemaBearingProperties,
        ];

        return [
            'type' => 'object',
            '$defs' => [
                'jsonSchemaObject' => $jsonSchemaObject,
            ],
            'properties' => array_merge(
                [
                    'type' => ['const' => 'object'],
                    '$schema' => ['type' => 'string'],
                    'required' => [
                        'type' => 'array',
                        'items' => ['type' => 'string'],
                    ],
                ],
                $schemaBearingProperties,
            ),
            'required' => ['type'],
        ];
    }
}
