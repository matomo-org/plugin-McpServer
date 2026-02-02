<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Normalization;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;

final class ToolDataNormalizer
{
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
            throw new ToolCallException("{$context} is invalid (field '{$field}').");
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

        throw new ToolCallException("{$context} is invalid (field '{$field}').");
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

        throw new ToolCallException("{$context} is invalid (field '{$field}').");
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requirePresentField(array $data, string $field, string $context): mixed
    {
        if (!array_key_exists($field, $data) || $data[$field] === null) {
            throw new ToolCallException("{$context} is incomplete (missing '{$field}').");
        }

        return $data[$field];
    }
}
