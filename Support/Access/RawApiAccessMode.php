<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Access;

use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;

final class RawApiAccessMode
{
    public const NONE = 'none';
    public const READ = 'read';
    public const CREATE = 'create';
    public const UPDATE = 'update';
    public const DELETE = 'delete';
    public const FULL = 'full';

    public const DEFAULT = self::NONE;

    /** @var list<string> */
    private const CRUD_MODES = [
        self::READ,
        self::CREATE,
        self::UPDATE,
        self::DELETE,
    ];

    public static function normalize(mixed $configuredMode): string
    {
        if (is_array($configuredMode)) {
            $tokens = $configuredMode;
        } elseif (is_scalar($configuredMode)) {
            $tokens = preg_split('/[\s,]+/', strtolower(trim((string) $configuredMode))) ?: [];
        } else {
            return self::DEFAULT;
        }

        $normalizedModes = [];
        foreach ($tokens as $token) {
            if (!is_scalar($token)) {
                continue;
            }

            $mode = strtolower(trim((string) $token));
            if ($mode === '' || $mode === self::NONE) {
                continue;
            }

            if ($mode === self::FULL) {
                return self::FULL;
            }

            if (in_array($mode, self::CRUD_MODES, true)) {
                $normalizedModes[$mode] = true;
            }
        }

        if ($normalizedModes === []) {
            return self::DEFAULT;
        }

        $orderedModes = [];
        foreach (self::CRUD_MODES as $mode) {
            if (isset($normalizedModes[$mode])) {
                $orderedModes[] = $mode;
            }
        }

        return implode(',', $orderedModes);
    }

    public static function fromBooleans(
        bool $read,
        bool $create,
        bool $update,
        bool $delete,
        bool $full,
    ): string {
        if ($full) {
            return self::FULL;
        }

        return self::normalize([
            $read ? self::READ : null,
            $create ? self::CREATE : null,
            $update ? self::UPDATE : null,
            $delete ? self::DELETE : null,
        ]);
    }

    public static function allowsToolRegistration(string $mode): bool
    {
        return self::normalize($mode) !== self::NONE;
    }

    public static function allowsCategory(string $mode, ?string $category): bool
    {
        $mode = self::normalize($mode);
        if ($mode === self::FULL) {
            return true;
        }

        $normalizedCategory = ApiMethodOperationClassifier::normalizeCategory($category);
        if ($normalizedCategory === '') {
            return false;
        }

        return in_array($normalizedCategory, explode(',', $mode), true);
    }
}
