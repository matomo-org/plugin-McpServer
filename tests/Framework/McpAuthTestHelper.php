<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Framework;

use Piwik\Access;
use Piwik\API\Request;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\Piwik;
use Piwik\Plugins\UsersManager\API as UsersManagerApi;
use Piwik\Plugins\UsersManager\Model as UsersManagerModel;
use Piwik\Tests\Framework\Fixture;

final class McpAuthTestHelper
{
    private static ?string $forcedTokenAuth = null;

    public static function asNoAccessUser(callable $callback): mixed
    {
        $originalTokenAuth = self::captureCurrentTokenAuth();
        $previousForcedTokenAuth = self::$forcedTokenAuth;
        $fixture = self::createNoAccessUserFixture();
        self::$forcedTokenAuth = $fixture['tokenAuth'];
        self::switchToTokenAuth($fixture['tokenAuth']);
        $callbackError = null;
        $result = null;

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $callbackError = $e;
        } finally {
            $cleanupError = null;

            self::switchToSuperUser();
            try {
                self::cleanupNoAccessUserFixture($fixture);
            } catch (\Throwable $e) {
                $cleanupError = $e;
            }

            self::restoreAuth($originalTokenAuth);
            self::$forcedTokenAuth = $previousForcedTokenAuth;

            if ($callbackError !== null) {
                throw $callbackError;
            }

            if ($cleanupError !== null) {
                throw new \RuntimeException('Failed cleaning up no-access user fixture.', 0, $cleanupError);
            }
        }

        return $result;
    }

    public static function asViewUserForSite(int $idSite, callable $callback): mixed
    {
        return self::asUserWithAccess(
            $callback,
            ['view' => [$idSite]],
            'MCP view access test token',
            'mcp_view_user',
        );
    }

    public static function asWriteUserForSite(int $idSite, callable $callback): mixed
    {
        return self::asUserWithAccess(
            $callback,
            ['write' => [$idSite]],
            'MCP write access test token',
            'mcp_write_user',
        );
    }

    public static function asAdminUserForSite(int $idSite, callable $callback): mixed
    {
        return self::asUserWithAccess(
            $callback,
            ['admin' => [$idSite]],
            'MCP admin access test token',
            'mcp_admin_user',
        );
    }

    /**
     * @param array<string, list<int>> $accessByLevel
     */
    public static function asUserWithSiteAccessLevels(array $accessByLevel, callable $callback): mixed
    {
        return self::asUserWithAccess(
            $callback,
            $accessByLevel,
            'MCP custom access test token',
            'mcp_access_user',
        );
    }

    public static function getForcedTokenAuth(): ?string
    {
        return self::$forcedTokenAuth;
    }

    /**
     * @return array{login: string, tokenAuth: string}
     */
    private static function createNoAccessUserFixture(?string $suffix = null): array
    {
        $unique = $suffix ?? substr(hash('sha256', uniqid('', true)), 0, 12);
        $login = 'mcp_no_access_user_' . $unique;
        $tokenAuth = (new UsersManagerModel())->generateRandomTokenAuth();
        \assert(\is_string($tokenAuth));

        UsersManagerApi::getInstance()->addUser($login, 'mcp-no-access-password', $login . '@example.test');
        (new UsersManagerModel())->addTokenAuth(
            $login,
            $tokenAuth,
            'MCP no access test token',
            Date::now()->getDatetime(),
        );

        return [
            'login' => $login,
            'tokenAuth' => $tokenAuth,
        ];
    }

    public static function switchToTokenAuth(string $tokenAuth): void
    {
        Piwik::postEvent('Request.initAuthenticationObject');

        $access = Access::getInstance();
        $access->setSuperUserAccess(false);
        $access->reloadAccess(StaticContainer::get('Piwik\Auth'));
        Request::reloadAuthUsingTokenAuth(['token_auth' => $tokenAuth]);
    }

    public static function switchToAnonymous(): void
    {
        self::switchToTokenAuth('anonymous');
    }

    public static function restoreAuth(?string $originalTokenAuth): void
    {
        if ($originalTokenAuth !== null && $originalTokenAuth !== '') {
            self::switchToTokenAuth($originalTokenAuth);
            return;
        }

        Piwik::postEvent('Request.initAuthenticationObject');

        $access = Access::getInstance();
        $access->setSuperUserAccess(true);
        $access->reloadAccess(StaticContainer::get('Piwik\Auth'));
    }

    public static function captureCurrentTokenAuth(): ?string
    {
        $tokenAuth = Fixture::getTokenAuth();
        return $tokenAuth !== '' ? $tokenAuth : null;
    }

    /**
     * @param array{login: string, tokenAuth: string} $fixture
     */
    private static function cleanupNoAccessUserFixture(array $fixture): void
    {
        $model = new UsersManagerModel();

        $model->deleteAllTokensForUser($fixture['login']);
        $model->deleteUser($fixture['login']);
    }

    private static function switchToSuperUser(): void
    {
        Piwik::postEvent('Request.initAuthenticationObject');

        $access = Access::getInstance();
        $access->setSuperUserAccess(true);
        $access->reloadAccess(StaticContainer::get('Piwik\Auth'));
    }

    /**
     * @param array<string, list<int>> $accessByLevel
     */
    private static function asUserWithAccess(
        callable $callback,
        array $accessByLevel,
        string $tokenDescription,
        string $loginPrefix,
    ): mixed {
        $originalTokenAuth = self::captureCurrentTokenAuth();
        $previousForcedTokenAuth = self::$forcedTokenAuth;
        $fixture = self::createUserFixture($accessByLevel, $tokenDescription, $loginPrefix);
        self::$forcedTokenAuth = $fixture['tokenAuth'];
        self::switchToTokenAuth($fixture['tokenAuth']);
        $callbackError = null;
        $result = null;

        try {
            $result = $callback();
        } catch (\Throwable $e) {
            $callbackError = $e;
        } finally {
            $cleanupError = null;

            self::switchToSuperUser();
            try {
                self::cleanupNoAccessUserFixture($fixture);
            } catch (\Throwable $e) {
                $cleanupError = $e;
            }

            self::restoreAuth($originalTokenAuth);
            self::$forcedTokenAuth = $previousForcedTokenAuth;

            if ($callbackError !== null) {
                throw $callbackError;
            }

            if ($cleanupError !== null) {
                throw new \RuntimeException('Failed cleaning up user access fixture.', 0, $cleanupError);
            }
        }

        return $result;
    }

    /**
     * @param array<string, list<int>> $accessByLevel
     * @return array{login: string, tokenAuth: string}
     */
    private static function createUserFixture(
        array $accessByLevel,
        string $tokenDescription,
        string $loginPrefix,
        ?string $suffix = null,
    ): array {
        $unique = $suffix ?? substr(hash('sha256', uniqid('', true)), 0, 12);
        $login = $loginPrefix . '_' . $unique;
        $tokenAuth = (new UsersManagerModel())->generateRandomTokenAuth();
        \assert(\is_string($tokenAuth));

        UsersManagerApi::getInstance()->addUser($login, 'mcp-access-password', $login . '@example.test');
        (new UsersManagerModel())->addTokenAuth(
            $login,
            $tokenAuth,
            $tokenDescription,
            Date::now()->getDatetime(),
        );

        foreach ($accessByLevel as $accessLevel => $idSites) {
            if ($idSites === []) {
                continue;
            }

            UsersManagerApi::getInstance()->setUserAccess($login, $accessLevel, $idSites);
        }

        return [
            'login' => $login,
            'tokenAuth' => $tokenAuth,
        ];
    }
}
