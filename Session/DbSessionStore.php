<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Session;

use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Matomo\Dependencies\McpServer\Symfony\Component\Uid\Uuid;
use Piwik\Common;
use Piwik\Config;
use Piwik\Db;
use Piwik\Piwik;

class DbSessionStore implements SessionStoreInterface
{
    private const DEFAULT_TTL_SECONDS = 3600;
    private const GC_BATCH_SIZE = 1000;

    private string $tableName;
    private int $ttl;

    public function __construct(?int $ttlSeconds = null)
    {
        $this->tableName = Common::prefixTable(DbSessionTable::TABLE_NAME);
        $this->ttl = self::normalizeTtl($ttlSeconds) ?? self::resolveConfiguredTtl();
    }

    public static function resolveConfiguredTtl(): int
    {
        $configuredTtl = Config::getInstance()->McpServer['session_ttl'] ?? null;
        $ttl = self::normalizeTtl($configuredTtl);

        return $ttl ?? self::DEFAULT_TTL_SECONDS;
    }

    public function exists(Uuid $id): bool
    {
        return $this->read($id) !== false;
    }

    public function read(Uuid $id): string|false
    {
        $sql = sprintf(
            'SELECT data, expires_at FROM `%s` WHERE id = ? AND login = ?',
            $this->tableName,
        );

        $row = Db::fetchRow($sql, [$id->toRfc4122(), $this->resolveCurrentLogin()]);
        if (!$row) {
            return false;
        }

        if ((int) ($row['expires_at'] ?? 0) <= time()) {
            $this->destroy($id);
            return false;
        }

        if (!isset($row['data'])) {
            $this->destroy($id);
            return false;
        }

        return (string) $row['data'];
    }

    public function write(Uuid $id, string $data): bool
    {
        $now = time();
        $expiresAt = $now + $this->ttl;
        $login = $this->resolveCurrentLogin();
        $sql = sprintf(
            'INSERT INTO `%s` (id, login, expires_at, data) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE login = VALUES(login), expires_at = VALUES(expires_at), data = VALUES(data)',
            $this->tableName,
        );

        Db::query($sql, [$id->toRfc4122(), $login, $expiresAt, $data]);

        return true;
    }

    public function destroy(Uuid $id): bool
    {
        $sql = sprintf('DELETE FROM `%s` WHERE id = ? AND login = ?', $this->tableName);

        Db::query($sql, [$id->toRfc4122(), $this->resolveCurrentLogin()]);

        return true;
    }

    public function gc(): array
    {
        $deletedRows = self::GC_BATCH_SIZE;
        $now = time();

        while ($deletedRows === self::GC_BATCH_SIZE) {
            $sql = sprintf(
                'DELETE FROM `%s` WHERE expires_at <= ? ORDER BY expires_at LIMIT %d',
                $this->tableName,
                self::GC_BATCH_SIZE,
            );

            $query = Db::query($sql, [$now]);
            $deletedRows = (int) $query->rowCount();
        }

        return [];
    }

    private static function normalizeTtl(mixed $ttl): ?int
    {
        if (!is_numeric($ttl)) {
            return null;
        }

        $normalized = (int) $ttl;

        if ($normalized <= 0) {
            return null;
        }

        return $normalized;
    }

    private function resolveCurrentLogin(): string
    {
        return Piwik::getCurrentUserLogin();
    }
}
