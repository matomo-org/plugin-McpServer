<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Piwik\Access;
use Piwik\Container\StaticContainer;
use Piwik\Date;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Controller;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\UsersManager\Model as UsersManagerModel;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ControllerTest extends IntegrationTestCase
{
    private ?string $anonymousAccessBackup = null;

    private bool $createdAnonymousUser = false;

    private int $idSite = 0;

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Controller Test Site',
            'https://mcp-controller.test',
        );
    }

    public function tearDown(): void
    {
        $this->restoreAnonymousAccessForSite($this->idSite);
        Access::getInstance()->setSuperUserAccess(false);

        parent::tearDown();
    }

    public function testConnectRejectsAnonymousWithViewAccess(): void
    {
        $this->setAnonymousAccessForSite($this->idSite, 'view');
        $originalTokenAuth = McpAuthTestHelper::captureCurrentTokenAuth();

        try {
            McpAuthTestHelper::switchToAnonymous();

            $this->expectException(NoAccessException::class);

            (new Controller(StaticContainer::get(SystemSettings::class)))->connect();
        } finally {
            McpAuthTestHelper::restoreAuth($originalTokenAuth);
        }
    }

    private function setAnonymousAccessForSite(int $idSite, string $access): void
    {
        $model = new UsersManagerModel();
        if (!$model->userExists('anonymous')) {
            $model->addUser('anonymous', 'not_a_hash', 'anonymous@example.com', Date::now()->getDatetime());
            $this->createdAnonymousUser = true;
        }

        if ($this->anonymousAccessBackup === null) {
            $usersAccess = $model->getUsersAccessFromSite($idSite);
            $this->anonymousAccessBackup = $usersAccess['anonymous'] ?? 'noaccess';
        }

        $model->deleteUserAccess('anonymous', [$idSite]);
        if ($access !== 'noaccess') {
            $model->addUserAccess('anonymous', $access, [$idSite]);
        }
    }

    private function restoreAnonymousAccessForSite(int $idSite): void
    {
        if ($this->anonymousAccessBackup === null) {
            return;
        }

        $model = new UsersManagerModel();
        $model->deleteUserAccess('anonymous', [$idSite]);
        if ($this->anonymousAccessBackup !== 'noaccess') {
            $model->addUserAccess('anonymous', $this->anonymousAccessBackup, [$idSite]);
        }

        if ($this->createdAnonymousUser) {
            $model->deleteUser('anonymous');
            $this->createdAnonymousUser = false;
        }

        $this->anonymousAccessBackup = null;
    }
}
