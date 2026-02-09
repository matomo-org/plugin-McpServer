<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Pagination;

final class SortDirection
{
    public const ASC = 'asc';
    public const DESC = 'desc';

    public static function isValid(string $direction): bool
    {
        return $direction === self::ASC || $direction === self::DESC;
    }
}
