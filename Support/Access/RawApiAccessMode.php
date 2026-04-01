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

    public static function normalize(mixed $configuredMode): string
    {
        if (!is_scalar($configuredMode)) {
            return self::DEFAULT;
        }

        $mode = strtolower(trim((string) $configuredMode));
        if (
            $mode !== self::NONE
            && $mode !== self::READ
            && $mode !== self::CREATE
            && $mode !== self::UPDATE
            && $mode !== self::DELETE
            && $mode !== self::FULL
        ) {
            return self::DEFAULT;
        }

        return $mode;
    }

    public static function allowsToolRegistration(string $mode): bool
    {
        return $mode !== self::NONE;
    }

    public static function allowsCategory(string $mode, ?string $category): bool
    {
        if ($mode === self::FULL) {
            return true;
        }

        $normalizedCategory = ApiMethodOperationClassifier::normalizeCategory($category);
        if ($normalizedCategory === '') {
            return false;
        }

        return match ($mode) {
            self::READ => $normalizedCategory === ApiMethodOperationClassifier::CATEGORY_READ,
            self::CREATE => $normalizedCategory === ApiMethodOperationClassifier::CATEGORY_READ
                || $normalizedCategory === ApiMethodOperationClassifier::CATEGORY_CREATE,
            self::UPDATE => $normalizedCategory === ApiMethodOperationClassifier::CATEGORY_READ
                || $normalizedCategory === ApiMethodOperationClassifier::CATEGORY_UPDATE,
            self::DELETE => $normalizedCategory === ApiMethodOperationClassifier::CATEGORY_READ
                || $normalizedCategory === ApiMethodOperationClassifier::CATEGORY_DELETE,
            default => false,
        };
    }
}
