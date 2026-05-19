<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Normalization;

use Piwik\Plugins\McpServer\Contracts\McpToolCallException;

final class ToolDataNormalizer
{
    /**
     * @return array<string, mixed>
     */
    public static function requireStringKeyedArray(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw new McpToolCallException(self::invalidMessage($context));
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new McpToolCallException(self::invalidMessage($context));
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function requireStringKeyedArrayOrEmptyList(mixed $value, string $context): array
    {
        if (!is_array($value)) {
            throw new McpToolCallException(self::invalidMessage($context));
        }

        if ($value === []) {
            return [];
        }

        return self::requireStringKeyedArray($value, $context);
    }

    private static function invalidMessage(string $context): string
    {
        if (str_ends_with($context, '.')) {
            return $context;
        }
        if (str_contains($context, ' is invalid')) {
            return "{$context}.";
        }

        return "{$context} is invalid.";
    }

    /**
     * @param array<string, mixed> $data
     *
     * Note: empty strings are accepted intentionally. This validator enforces
     * presence (non-null) and type, not non-empty semantic content.
     */
    public static function requireStringField(array $data, string $field, string $context): string
    {
        $value = self::requirePresentField($data, $field, $context);
        if (!is_string($value)) {
            throw new McpToolCallException("{$context} is invalid (field '{$field}').");
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function requireIntLikeField(array $data, string $field, string $context): int
    {
        $value = self::requirePresentField($data, $field, $context);
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new McpToolCallException("{$context} is invalid (field '{$field}').");
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function requireBoolLikeField(array $data, string $field, string $context): bool
    {
        $value = self::requirePresentField($data, $field, $context);
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1 || $value === '0' || $value === '1') {
            return (bool) $value;
        }

        throw new McpToolCallException("{$context} is invalid (field '{$field}').");
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requirePresentField(array $data, string $field, string $context): mixed
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            throw new McpToolCallException("{$context} is incomplete (missing '{$field}').");
        }

        return $data[$field];
    }
}
