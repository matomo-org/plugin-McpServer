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
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SystemSettingsTest extends IntegrationTestCase
{
    private ?SystemSettings $settings = null;
    private bool $originalEnableMcp = false;
    private string $originalRawApiAccessMode = RawApiAccessMode::NONE;

    public function setUp(): void
    {
        parent::setUp();

        $this->settings = StaticContainer::get(SystemSettings::class);
        self::assertInstanceOf(SystemSettings::class, $this->settings);
        $this->originalEnableMcp = $this->settings->isMcpEnabled();
        $this->originalRawApiAccessMode = $this->settings->getRawApiAccessMode();
    }

    public function tearDown(): void
    {
        self::assertInstanceOf(SystemSettings::class, $this->settings);
        $hadSuperUserAccess = Access::getInstance()->hasSuperUserAccess();
        Access::getInstance()->setSuperUserAccess(true);

        try {
            $this->settings->enableMcp->setValue($this->originalEnableMcp);
            $this->settings->rawApiAccessMode->setValue($this->originalRawApiAccessMode);
        } finally {
            Access::getInstance()->setSuperUserAccess($hadSuperUserAccess);
        }

        parent::tearDown();
    }

    public function testMcpIsDisabledByDefault(): void
    {
        self::assertInstanceOf(SystemSettings::class, $this->settings);
        self::assertFalse($this->settings->isMcpEnabled());
    }

    public function testCanEnableMcp(): void
    {
        self::assertInstanceOf(SystemSettings::class, $this->settings);
        $access = Access::getInstance();
        $hadSuperUserAccess = $access->hasSuperUserAccess();
        $access->setSuperUserAccess(true);

        try {
            $this->settings->enableMcp->setValue(true);
            self::assertTrue($this->settings->isMcpEnabled());
        } finally {
            $access->setSuperUserAccess($hadSuperUserAccess);
        }
    }

    public function testRawApiAccessModeDefaultsToNone(): void
    {
        self::assertInstanceOf(SystemSettings::class, $this->settings);
        self::assertSame(RawApiAccessMode::NONE, $this->settings->getRawApiAccessMode());
    }

    public function testCanChangeRawApiAccessMode(): void
    {
        self::assertInstanceOf(SystemSettings::class, $this->settings);
        $access = Access::getInstance();
        $hadSuperUserAccess = $access->hasSuperUserAccess();
        $access->setSuperUserAccess(true);

        try {
            $this->settings->rawApiAccessMode->setValue(RawApiAccessMode::READ);
            self::assertSame(RawApiAccessMode::READ, $this->settings->getRawApiAccessMode());

            $this->settings->rawApiAccessMode->setValue(RawApiAccessMode::FULL);
            self::assertSame(RawApiAccessMode::FULL, $this->settings->getRawApiAccessMode());
        } finally {
            $access->setSuperUserAccess($hadSuperUserAccess);
        }
    }
}
