<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Pagination;

final class SegmentsPagination
{
    public const SORT_NAME_ASC = 'name_asc';
    public const SORT_NAME_DESC = 'name_desc';
    public const SORT_ID_ASC = 'id_asc';
    public const SORT_ID_DESC = 'id_desc';

    public const LIMIT_DEFAULT = 100;
    public const LIMIT_MAX = 500;

    public static function createConfig(): PaginationConfig
    {
        return new PaginationConfig(
            self::LIMIT_DEFAULT,
            self::LIMIT_MAX,
            self::SORT_NAME_ASC,
            [
                new SortSpec(
                    self::SORT_NAME_ASC,
                    new KeySpec('name', KeySpec::TYPE_STRING, SortDirection::ASC),
                    [new KeySpec('idsegment', KeySpec::TYPE_INT, SortDirection::ASC)],
                ),
                new SortSpec(
                    self::SORT_NAME_DESC,
                    new KeySpec('name', KeySpec::TYPE_STRING, SortDirection::DESC),
                    [new KeySpec('idsegment', KeySpec::TYPE_INT, SortDirection::DESC)],
                ),
                new SortSpec(
                    self::SORT_ID_ASC,
                    new KeySpec('idsegment', KeySpec::TYPE_INT, SortDirection::ASC),
                ),
                new SortSpec(
                    self::SORT_ID_DESC,
                    new KeySpec('idsegment', KeySpec::TYPE_INT, SortDirection::DESC),
                ),
            ],
        );
    }
}
