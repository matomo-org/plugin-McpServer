<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SystemSettingsTest extends IntegrationTestCase
{
    private ?SystemSettings $settings = null;

    public function setUp(): void
    {
        parent::setUp();

        $this->settings = $this->createSettingsWithOAuth2Enabled(false);
    }

    public function testMcpIsDisabledByDefault(): void
    {
        self::assertInstanceOf(SystemSettings::class, $this->settings);
        self::assertFalse($this->settings->isMcpEnabled());
    }

    public function testCanEnableMcp(): void
    {
        self::assertInstanceOf(SystemSettings::class, $this->settings);
        $this->settings->enableMcp->setValue(true);
        self::assertTrue($this->settings->isMcpEnabled());
    }

    private function createSettingsWithOAuth2Enabled(bool $oauth2Enabled): SystemSettings
    {
        $gateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $gateway->method('isPluginActivated')
            ->with('OAuth2')
            ->willReturn($oauth2Enabled);

        return new SystemSettings($gateway);
    }
}
