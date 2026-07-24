<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\Support\Access;

use Piwik\Access;
use Piwik\Container\StaticContainer;
use Piwik\Http\BadRequestException;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Support\Access\McpAccessGate;
use Piwik\Plugins\McpServer\Support\Access\McpAccessLevel;
use Piwik\Plugins\McpServer\Support\Access\McpUnavailableException;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointSpec;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpAccessGateTest extends IntegrationTestCase
{
    private bool $originalEnableMcp = false;
    private string $originalMaximumAllowedMcpAccessLevel = McpAccessLevel::UNLIMITED;

    public function setUp(): void
    {
        parent::setUp();
        $settings = $this->settings();
        $this->originalEnableMcp = (bool) $settings->enableMcp->getValue();
        $this->originalMaximumAllowedMcpAccessLevel = $settings->getMaximumAllowedMcpAccessLevel();

        $this->withSuperUser(function () use ($settings): void {
            $settings->enableMcp->setValue(true);
            $settings->maximumMcpAccessLevel->setValue(McpAccessLevel::UNLIMITED);
        });
    }

    public function tearDown(): void
    {
        $settings = $this->settings();
        $this->withSuperUser(function () use ($settings): void {
            $settings->enableMcp->setValue($this->originalEnableMcp);
            $settings->maximumMcpAccessLevel->setValue($this->originalMaximumAllowedMcpAccessLevel);
        });
        Access::getInstance()->setSuperUserAccess(true);
        parent::tearDown();
    }

    public function testAssertBaseAcceptsSuperUserWithMcpEnabled(): void
    {
        Access::getInstance()->setSuperUserAccess(true);

        // assertBase() returns void and throws on failure; reaching the end without
        // an exception is the success condition.
        $this->gate()->assertBase();
    }

    public function testAssertBaseRejectsAnonymousUser(): void
    {
        Access::getInstance()->setSuperUserAccess(false);

        $this->expectException(NoAccessException::class);

        $this->gate()->assertBase();
    }

    public function testAssertBaseRejectsWhenMcpIsDisabled(): void
    {
        $this->withSuperUser(function (): void {
            $this->settings()->enableMcp->setValue(false);
        });
        Access::getInstance()->setSuperUserAccess(true);

        $this->expectException(McpUnavailableException::class);
        $this->expectExceptionMessage(McpEndpointSpec::DISABLED_ERROR);

        $this->gate()->assertBase();
    }

    public function testAssertHttpAcceptsSuperUserWhenCeilingIsUnlimited(): void
    {
        Access::getInstance()->setSuperUserAccess(true);

        // assertHttp() returns void and throws on failure; reaching the end without
        // an exception is the success condition.
        $this->gate()->assertHttp();
    }

    public function testAssertHttpRejectsSuperUserWhenCeilingIsBelowSuperuser(): void
    {
        $this->withSuperUser(function (): void {
            $this->settings()->maximumMcpAccessLevel->setValue(McpAccessLevel::VIEW);
        });
        Access::getInstance()->setSuperUserAccess(true);

        $this->expectException(BadRequestException::class);
        $this->expectExceptionMessageMatches('/too high privilege level/');

        $this->gate()->assertHttp();
    }

    public function testAssertHttpRejectionPathReusesBaseChecksFirst(): void
    {
        // Disabling MCP should win over the ceiling check: even with a
        // ceiling that the user would exceed, the disabled message is the
        // canonical rejection text from assertBase(), not the ceiling text.
        $this->withSuperUser(function (): void {
            $this->settings()->enableMcp->setValue(false);
            $this->settings()->maximumMcpAccessLevel->setValue(McpAccessLevel::VIEW);
        });
        Access::getInstance()->setSuperUserAccess(true);

        $this->expectException(McpUnavailableException::class);
        $this->expectExceptionMessage(McpEndpointSpec::DISABLED_ERROR);

        $this->gate()->assertHttp();
    }

    private function gate(): McpAccessGate
    {
        return new McpAccessGate($this->settings());
    }

    private function settings(): SystemSettings
    {
        $settings = StaticContainer::get(SystemSettings::class);
        self::assertInstanceOf(SystemSettings::class, $settings);
        return $settings;
    }

    private function withSuperUser(callable $callback): void
    {
        $access = Access::getInstance();
        $had = $access->hasSuperUserAccess();
        $access->setSuperUserAccess(true);
        try {
            $callback();
        } finally {
            $access->setSuperUserAccess($had);
        }
    }
}
