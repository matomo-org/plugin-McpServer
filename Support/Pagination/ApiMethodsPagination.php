<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Pagination;

final class ApiMethodsPagination
{
    public const SORT_MODULE_ASC = 'module_asc';
    public const SORT_MODULE_DESC = 'module_desc';
    public const SORT_METHOD_ASC = 'method_asc';
    public const SORT_METHOD_DESC = 'method_desc';

    public const LIMIT_DEFAULT = 100;
    public const LIMIT_MAX = 500;

    public static function createConfig(): PaginationConfig
    {
        return new PaginationConfig(
            self::LIMIT_DEFAULT,
            self::LIMIT_MAX,
            self::SORT_MODULE_ASC,
            [
                new SortSpec(
                    self::SORT_MODULE_ASC,
                    new KeySpec('module', KeySpec::TYPE_STRING, SortDirection::ASC),
                    [new KeySpec('method', KeySpec::TYPE_STRING, SortDirection::ASC)],
                ),
                new SortSpec(
                    self::SORT_MODULE_DESC,
                    new KeySpec('module', KeySpec::TYPE_STRING, SortDirection::DESC),
                    [new KeySpec('method', KeySpec::TYPE_STRING, SortDirection::DESC)],
                ),
                new SortSpec(
                    self::SORT_METHOD_ASC,
                    new KeySpec('method', KeySpec::TYPE_STRING, SortDirection::ASC),
                ),
                new SortSpec(
                    self::SORT_METHOD_DESC,
                    new KeySpec('method', KeySpec::TYPE_STRING, SortDirection::DESC),
                ),
            ],
        );
    }
}
