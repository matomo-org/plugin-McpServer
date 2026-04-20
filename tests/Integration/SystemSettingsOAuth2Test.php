<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Piwik\Container\StaticContainer;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SystemSettingsOAuth2Test extends IntegrationTestCase
{
    public function testDetectsOAuth2PluginActivationThroughContainerDecoration(): void
    {
        $settings = StaticContainer::get(SystemSettings::class);

        self::assertInstanceOf(SystemSettings::class, $settings);
        self::assertTrue($settings->isOAuth2PluginEnabled());
    }

    /**
     * @return array<string, bool>
     */
    public function provideContainerConfig(): array
    {
        return [
            'test.vars.mockOAuth2PluginEnabled' => true,
        ];
    }
}
