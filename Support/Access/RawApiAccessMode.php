<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Access;

final class RawApiAccessMode
{
    public const NONE = 'none';
    public const READ = 'read';
    public const FULL = 'full';

    public const DEFAULT = self::NONE;

    public static function normalize(mixed $configuredMode): string
    {
        if (!is_scalar($configuredMode)) {
            return self::DEFAULT;
        }

        $mode = strtolower(trim((string) $configuredMode));
        if (
            $mode !== self::NONE
            && $mode !== self::READ
            && $mode !== self::FULL
        ) {
            return self::DEFAULT;
        }

        return $mode;
    }

    public static function allowsToolRegistration(string $mode): bool
    {
        return $mode === self::READ || $mode === self::FULL;
    }
}
