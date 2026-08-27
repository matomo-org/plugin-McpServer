<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Normalization;

/**
 * Decodes a JSON object supplied as a string, for the fields registered in
 * {@see ToolIntakeProfile::$objectFields}.
 *
 * The decode is narrow:
 *
 * - only a JSON object is accepted - lists, scalars and trailing content are rejected;
 * - it runs once, so a string inside the decoded object stays a string;
 * - nesting and property count are bounded;
 * - a bare-number key at the top level (`{"0":1}`, `{"1":1,"a":2}`) is rejected, because
 *   decoding loses the key/index distinction and no Matomo parameter is named that way. Deeper
 *   keys are unaffected, so `{"a":{"0":"x"}}` decodes to a nested list;
 * - duplicate keys resolve as json_decode() resolves them, the last occurrence winning.
 *
 * Exception messages name the path only, never the decoded string, which may contain a token or
 * a segment expression.
 */
final class BoundedJsonObjectDecoder
{
    public const MAX_DEPTH = 20;
    public const MAX_PROPERTIES = 200;

    /**
     * JSON's own whitespace set, which is PHP's default `trim()` list minus `\0` and `\x0B`.
     * Those two are control characters `json_decode()` refuses, and they reach a parameter string
     * as the legal outer-JSON escapes for U+0000 and U+000B, so trimming them here would accept
     * as leading whitespace a byte the decode below would reject.
     */
    private const TRIM_CHARACTERS = " \t\n\r";

    /**
     * Whether a value is visibly an attempted JSON object.
     *
     * Callers test this before {@see decode()} so a bare word, a number or a quoted scalar keeps
     * its schema rejection instead of becoming a decode failure. Both trim TRIM_CHARACTERS, so
     * they agree on leading whitespace.
     */
    public static function looksLikeJsonObject(mixed $value): bool
    {
        return is_string($value) && str_starts_with(trim($value, self::TRIM_CHARACTERS), '{');
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $raw, string $path): array
    {
        $trimmed = trim($raw, self::TRIM_CHARACTERS);
        if ($trimmed === '' || !str_starts_with($trimmed, '{')) {
            throw self::invalid($path);
        }

        try {
            // json_decode()'s $depth counts a scalar as one level, so MAX_DEPTH + 1 permits
            // MAX_DEPTH nested containers. Lists count as containers here.
            $decoded = json_decode($trimmed, true, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw self::invalid($path);
        }

        if (!is_array($decoded)) {
            throw self::invalid($path);
        }

        if ($decoded !== [] && array_is_list($decoded)) {
            throw self::invalid($path);
        }

        foreach (array_keys($decoded) as $key) {
            if (!is_string($key)) {
                throw self::invalid($path);
            }
        }

        self::assertWithinPropertyBound($decoded, $path);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param array<mixed> $decoded
     */
    private static function assertWithinPropertyBound(array $decoded, string $path): void
    {
        // Object properties are bounded, list entries are not: a parameter may legitimately
        // carry a long list, such as every site ID. Lists are still walked, so an object nested
        // inside one is bounded.
        if (!array_is_list($decoded) && count($decoded) > self::MAX_PROPERTIES) {
            throw self::invalid($path);
        }

        foreach ($decoded as $value) {
            if (is_array($value)) {
                self::assertWithinPropertyBound($value, $path);
            }
        }
    }

    private static function invalid(string $path): ArgumentIssueException
    {
        return new ArgumentIssueException(
            ArgumentIssueException::REASON_INVALID_JSON_OBJECT,
            [$path],
            "Value at '{$path}' must be an object, or a single JSON object string "
            . 'with at most ' . self::MAX_DEPTH . ' nesting levels and '
            . self::MAX_PROPERTIES . ' properties per object.',
        );
    }
}
