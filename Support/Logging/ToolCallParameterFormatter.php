<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Logging;

final class ToolCallParameterFormatter
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function format(array $arguments, bool $full): string
    {
        unset($arguments['_session'], $arguments['_request']);

        if ($arguments === []) {
            return '';
        }

        $parts = [];

        foreach ($arguments as $key => $value) {
            $parts[] = sprintf('%s: %s', (string) $key, $this->formatValue($value, $full));
        }

        return implode(', ', $parts);
    }

    private function formatValue(mixed $value, bool $full): string
    {
        if (!$full) {
            return $this->formatRedactedValue($value);
        }

        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return is_string($encoded) ? $encoded : '<string:' . strlen($value) . '>';
        }

        if (is_array($value) || is_object($value)) {
            $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (is_string($encoded)) {
                return $encoded;
            }
        }

        return $this->formatRedactedValue($value);
    }

    private function formatRedactedValue(mixed $value): string
    {
        if (is_int($value)) {
            return '<int>';
        }

        if (is_float($value)) {
            return '<float>';
        }

        if (is_bool($value)) {
            return '<bool>';
        }

        if ($value === null) {
            return '<null>';
        }

        if (is_string($value)) {
            return '<string:' . strlen($value) . '>';
        }

        if (is_array($value)) {
            $count = count($value);

            if (array_is_list($value)) {
                return '<array:' . $count . '>';
            }

            return '<object:' . $count . '>';
        }

        if (is_object($value)) {
            return '<object:' . $value::class . '>';
        }

        return '<unknown>';
    }
}
