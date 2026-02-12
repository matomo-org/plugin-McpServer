<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Session;

use Piwik\Common;
use Piwik\Db;
use Piwik\DbHelper;

class DbSessionTable
{
    public const TABLE_NAME = 'mcp_session';

    public function install(): void
    {
        $definition = '
            `id` VARCHAR(191) NOT NULL,
            `expires_at` INTEGER NOT NULL,
            `data` MEDIUMTEXT,
            PRIMARY KEY (`id`),
            INDEX `index_expires_at` (`expires_at`)
        ';

        DbHelper::createTable(self::TABLE_NAME, $definition);
    }

    public function uninstall(): void
    {
        Db::query('DROP TABLE IF EXISTS ' . Common::prefixTable(self::TABLE_NAME));
    }
}
