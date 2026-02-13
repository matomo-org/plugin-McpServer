<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Framework;

use PHPUnit\Framework\Assert;

final class ContractShapeAssert
{
    /**
     * @param array<string, mixed> $schema
     */
    public static function assertMatchesSchema(array $schema, mixed $data, string $path = '$'): void
    {
        $typeSpec = $schema['type'] ?? null;
        if ($typeSpec === null) {
            return;
        }

        $types = is_array($typeSpec) ? array_values($typeSpec) : [$typeSpec];
        Assert::assertNotEmpty($types, 'Schema type must not be empty at ' . $path);

        if ($data === null) {
            Assert::assertTrue(in_array('null', $types, true), 'Expected non-null at ' . $path);
            return;
        }

        $selectedType = self::selectMatchingType($types, $data);
        Assert::assertNotNull(
            $selectedType,
            'Type mismatch at ' . $path . '. Allowed: ' . implode(', ', $types)
        );

        if ($selectedType === 'object') {
            self::assertObjectSchema($schema, $data, $path);
            return;
        }

        if ($selectedType === 'array') {
            self::assertArraySchema($schema, $data, $path);
        }
    }

    /**
     * @param array<int, mixed> $types
     */
    private static function selectMatchingType(array $types, mixed $data): ?string
    {
        foreach ($types as $type) {
            if (!is_string($type)) {
                continue;
            }

            if (self::matchesType($type, $data)) {
                return $type;
            }
        }

        return null;
    }

    private static function matchesType(string $type, mixed $data): bool
    {
        return match ($type) {
            'string' => is_string($data),
            'integer' => is_int($data),
            'number' => is_int($data) || is_float($data),
            'boolean' => is_bool($data),
            'array' => is_array($data) && array_is_list($data),
            'object' => is_array($data),
            'null' => $data === null,
            default => false,
        };
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function assertObjectSchema(array $schema, mixed $data, string $path): void
    {
        Assert::assertIsArray($data, 'Expected object at ' . $path);

        $required = $schema['required'] ?? [];
        if (is_array($required)) {
            foreach ($required as $requiredKey) {
                if (!is_string($requiredKey)) {
                    continue;
                }

                Assert::assertArrayHasKey($requiredKey, $data, 'Missing required key at ' . $path . '.' . $requiredKey);
            }
        }

        $properties = $schema['properties'] ?? [];
        if (is_array($properties)) {
            foreach ($properties as $key => $propertySchema) {
                if (!is_string($key) || !is_array($propertySchema) || !array_key_exists($key, $data)) {
                    continue;
                }

                /** @var array<string, mixed> $propertySchema */
                self::assertMatchesSchema($propertySchema, $data[$key], $path . '.' . $key);
            }
        }

        if (($schema['additionalProperties'] ?? true) === false && is_array($properties)) {
            foreach ($data as $key => $_value) {
                if (!is_string($key)) {
                    continue;
                }

                Assert::assertArrayHasKey(
                    $key,
                    $properties,
                    'Unexpected key at ' . $path . '.' . $key
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $schema
     */
    private static function assertArraySchema(array $schema, mixed $data, string $path): void
    {
        Assert::assertIsArray($data, 'Expected array at ' . $path);
        Assert::assertTrue(array_is_list($data), 'Expected list array at ' . $path);

        $itemSchema = $schema['items'] ?? null;
        if (!is_array($itemSchema)) {
            return;
        }

        /** @var array<string, mixed> $typedItemSchema */
        $typedItemSchema = $itemSchema;
        foreach ($data as $index => $item) {
            self::assertMatchesSchema($typedItemSchema, $item, $path . '[' . $index . ']');
        }
    }
}
