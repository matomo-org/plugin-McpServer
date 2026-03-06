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

final class ViewAccessFallback
{
    public static function shouldReturnEmptyOnNoAccessFallbackForSiteIds(mixed $siteIds): bool
    {
        return !is_array($siteIds) || $siteIds === [];
    }

    public static function shouldReturnEmptyOnNoAccessFallback(): bool
    {
        $siteIds = Access::getInstance()->getSitesIdWithAtLeastViewAccess();

        return self::shouldReturnEmptyOnNoAccessFallbackForSiteIds($siteIds);
    }
}
