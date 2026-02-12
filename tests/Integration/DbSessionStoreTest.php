<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Matomo\Dependencies\McpServer\Symfony\Component\Uid\Uuid;
use Piwik\Common;
use Piwik\Config;
use Piwik\Db;
use Piwik\Plugins\McpServer\Session\DbSessionStore;
use Piwik\Plugins\McpServer\Session\DbSessionTable;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Piwik;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class DbSessionStoreTest extends IntegrationTestCase
{
    private string $tableName = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->tableName = Common::prefixTable(DbSessionTable::TABLE_NAME);
    }

    public function testWriteAndReadRoundTrip(): void
    {
        $store = new DbSessionStore(3600);
        $id = Uuid::v4();
        $now = time();

        $this->assertTrue($store->write($id, 'payload'));
        $this->assertSame('payload', $store->read($id));
        $this->assertTrue($store->exists($id));

        $expiresAt = $this->fetchExpiresAt($id);
        $this->assertNotNull($expiresAt);
        $this->assertGreaterThanOrEqual($now + 3598, $expiresAt);
        $this->assertLessThanOrEqual($now + 3602, $expiresAt);
        $this->assertSame(Piwik::getCurrentUserLogin(), $this->fetchLogin($id));
    }

    public function testReadMissingReturnsFalse(): void
    {
        $store = new DbSessionStore(3600);
        $id = Uuid::v4();

        $this->assertFalse($store->read($id));
        $this->assertFalse($store->exists($id));
    }

    public function testDestroyRemovesSession(): void
    {
        $store = new DbSessionStore(3600);
        $id = Uuid::v4();

        $store->write($id, 'payload');
        $this->assertTrue($store->destroy($id));

        $this->assertFalse($store->read($id));
        $this->assertRowMissing($id);
    }

    public function testReadReturnsFalseForDifferentAuthenticatedUser(): void
    {
        $store = new DbSessionStore(3600);
        $id = Uuid::v4();

        $store->write($id, 'payload');

        McpAuthTestHelper::asNoAccessUser(function () use ($store, $id): void {
            $this->assertFalse($store->read($id));
            $this->assertFalse($store->exists($id));
        });
    }

    public function testDestroyDoesNotDeleteSessionForDifferentAuthenticatedUser(): void
    {
        $store = new DbSessionStore(3600);
        $id = Uuid::v4();

        $store->write($id, 'payload');
        $ownerLogin = Piwik::getCurrentUserLogin();

        McpAuthTestHelper::asNoAccessUser(function () use ($store, $id): void {
            $this->assertTrue($store->destroy($id));
            $this->assertFalse($store->read($id));
        });

        $this->assertSame($ownerLogin, $this->fetchLogin($id));
        $this->assertSame('payload', $this->fetchPayload($id));
    }

    public function testWriteStoresAnonymousLoginWhenAuthenticatedAsAnonymous(): void
    {
        $store = new DbSessionStore(3600);
        $id = Uuid::v4();
        $originalTokenAuth = McpAuthTestHelper::captureCurrentTokenAuth();

        try {
            McpAuthTestHelper::switchToAnonymous();
            $store->write($id, 'payload');
            $this->assertSame('anonymous', $this->fetchLogin($id));
            $this->assertSame('payload', $store->read($id));
        } finally {
            McpAuthTestHelper::restoreAuth($originalTokenAuth);
        }
    }

    public function testExpiredSessionIsRemovedOnRead(): void
    {
        $store = new DbSessionStore(1);
        $id = Uuid::v4();

        $store->write($id, 'payload');
        $this->expireSession($id, 5);

        $this->assertFalse($store->read($id));
        $this->assertRowMissing($id);
    }

    public function testNullPayloadIsRemovedOnRead(): void
    {
        $store = new DbSessionStore(3600);
        $id = Uuid::v4();

        $store->write($id, 'payload');
        Db::query(
            sprintf('UPDATE `%s` SET data = NULL WHERE id = ?', $this->tableName),
            [$id->toRfc4122()]
        );

        $this->assertFalse($store->read($id));
        $this->assertRowMissing($id);
    }

    public function testGcRemovesExpiredSessions(): void
    {
        $store = new DbSessionStore(1);
        $id = Uuid::v4();

        $store->write($id, 'payload');
        $this->expireSession($id, 5);

        $store->gc();

        $this->assertFalse($store->read($id));
        $this->assertRowMissing($id);
    }

    public function testGcRemovesExpiredSessionsInBatches(): void
    {
        $store = new DbSessionStore(3600);

        for ($i = 0; $i < 1005; $i++) {
            $id = Uuid::v4();
            $store->write($id, 'expired');
            $this->expireSession($id, 5);
        }

        $validId = Uuid::v4();
        $store->write($validId, 'alive');

        $store->gc();

        $remaining = Db::fetchOne(sprintf('SELECT COUNT(*) FROM `%s`', $this->tableName));
        $this->assertSame(1, (int) $remaining);
        $this->assertSame('alive', $store->read($validId));
    }

    public function testResolveConfiguredTtlUsesPluginConfigValue(): void
    {
        Config::getInstance()->McpServer = ['session_ttl' => 7200];

        $this->assertSame(7200, DbSessionStore::resolveConfiguredTtl());
    }

    public function testResolveConfiguredTtlFallsBackToDefaultWhenInvalid(): void
    {
        Config::getInstance()->McpServer = ['session_ttl' => 0];
        $this->assertSame(3600, DbSessionStore::resolveConfiguredTtl());

        Config::getInstance()->McpServer = ['session_ttl' => -5];
        $this->assertSame(3600, DbSessionStore::resolveConfiguredTtl());

        Config::getInstance()->McpServer = ['session_ttl' => 'invalid'];
        $this->assertSame(3600, DbSessionStore::resolveConfiguredTtl());
    }

    public function testConstructorUsesConfiguredTtlWhenNoExplicitValue(): void
    {
        Config::getInstance()->McpServer = ['session_ttl' => 2];
        $store = new DbSessionStore();
        $id = Uuid::v4();
        $now = time();

        $store->write($id, 'payload');

        $expiresAt = $this->fetchExpiresAt($id);
        $this->assertNotNull($expiresAt);
        $this->assertGreaterThanOrEqual($now + 1, $expiresAt);
        $this->assertLessThanOrEqual($now + 3, $expiresAt);
    }

    public function testExplicitConstructorTtlOverridesConfig(): void
    {
        Config::getInstance()->McpServer = ['session_ttl' => 7200];
        $store = new DbSessionStore(10);
        $id = Uuid::v4();
        $now = time();

        $store->write($id, 'payload');

        $expiresAt = $this->fetchExpiresAt($id);
        $this->assertNotNull($expiresAt);
        $this->assertGreaterThanOrEqual($now + 8, $expiresAt);
        $this->assertLessThanOrEqual($now + 12, $expiresAt);
    }

    private function expireSession(Uuid $id, int $secondsAgo): void
    {
        $timestamp = time() - $secondsAgo;
        Db::query(
            sprintf('UPDATE `%s` SET expires_at = ? WHERE id = ?', $this->tableName),
            [$timestamp, $id->toRfc4122()]
        );
    }

    private function fetchExpiresAt(Uuid $id): ?int
    {
        $row = Db::fetchRow(
            sprintf('SELECT expires_at FROM `%s` WHERE id = ?', $this->tableName),
            [$id->toRfc4122()]
        );

        if (!array_key_exists('expires_at', $row) || $row['expires_at'] === null) {
            return null;
        }

        return (int) $row['expires_at'];
    }

    private function fetchLogin(Uuid $id): ?string
    {
        $row = Db::fetchRow(
            sprintf('SELECT login FROM `%s` WHERE id = ?', $this->tableName),
            [$id->toRfc4122()]
        );

        if (!array_key_exists('login', $row) || $row['login'] === null || !is_scalar($row['login'])) {
            return null;
        }

        return (string) $row['login'];
    }

    private function fetchPayload(Uuid $id): ?string
    {
        $row = Db::fetchRow(
            sprintf('SELECT data FROM `%s` WHERE id = ?', $this->tableName),
            [$id->toRfc4122()]
        );

        if (!array_key_exists('data', $row) || $row['data'] === null || !is_scalar($row['data'])) {
            return null;
        }

        return (string) $row['data'];
    }

    private function assertRowMissing(Uuid $id): void
    {
        $row = Db::fetchRow(
            sprintf('SELECT id FROM `%s` WHERE id = ?', $this->tableName),
            [$id->toRfc4122()]
        );

        $this->assertFalse($row);
    }
}
