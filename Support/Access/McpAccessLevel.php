<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Access;

use Piwik\Access;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointSpec;

final class McpAccessLevel
{
    public const UNLIMITED = 'unlimited';
    public const VIEW = 'view';
    public const WRITE = 'write';
    public const ADMIN = 'admin';
    public const SUPERUSER = 'superuser';

    /**
     * @return list<string>
     */
    public static function getConfigurableLevels(): array
    {
        return [
            self::UNLIMITED,
            self::VIEW,
            self::WRITE,
            self::ADMIN,
        ];
    }

    public static function normalizeMaximumAllowed(mixed $value): string
    {
        if (!is_scalar($value)) {
            return self::UNLIMITED;
        }

        $normalizedValue = strtolower(trim((string) $value));

        return in_array($normalizedValue, self::getConfigurableLevels(), true)
            ? $normalizedValue
            : self::UNLIMITED;
    }

    public static function resolveCurrentUserLevel(): string
    {
        $access = Access::getInstance();

        if ($access->hasSuperUserAccess()) {
            return self::SUPERUSER;
        }

        if (Piwik::isUserHasSomeAdminAccess()) {
            return self::ADMIN;
        }

        if (Piwik::isUserHasSomeWriteAccess()) {
            return self::WRITE;
        }

        return self::VIEW;
    }

    public static function exceedsMaximumAllowed(string $currentLevel, string $maximumAllowedLevel): bool
    {
        $normalizedMaximum = self::normalizeMaximumAllowed($maximumAllowedLevel);

        if ($normalizedMaximum === self::UNLIMITED) {
            return false;
        }

        return self::getRank($currentLevel) > self::getRank($normalizedMaximum);
    }

    public static function getDisplayName(string $level): string
    {
        return match ($level) {
            self::VIEW => 'View',
            self::WRITE => 'Write',
            self::ADMIN => 'Admin',
            self::SUPERUSER => 'Superuser',
            default => 'Unlimited',
        };
    }

    public static function createTooHighPrivilegeMessage(string $maximumAllowedLevel): string
    {
        return sprintf(
            McpEndpointSpec::TOO_HIGH_PRIVILEGE_ERROR,
            self::getDisplayName(self::normalizeMaximumAllowed($maximumAllowedLevel)),
        );
    }

    private static function getRank(string $level): int
    {
        return match ($level) {
            self::VIEW => 1,
            self::WRITE => 2,
            self::ADMIN => 3,
            self::SUPERUSER => 4,
            default => 0,
        };
    }
}
