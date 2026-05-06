<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Auth;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Auth\ProtectedResourceMetadataProvider;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @group McpServer
 * @group Plugins
 */
class ProtectedResourceMetadataProviderTest extends TestCase
{
    public function testIsUnavailableWhenOAuth2PluginIsDisabled(): void
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('isOAuth2PluginEnabled')->willReturn(false);

        $provider = $this->createProvider($settings, 'https://matomo.example.test');

        self::assertFalse($provider->isAvailable());
    }

    public function testBuildsProtectedResourceMetadata(): void
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('isOAuth2PluginEnabled')->willReturn(true);

        $provider = $this->createProvider($settings, 'https://matomo.example.test/');

        self::assertSame(
            'https://matomo.example.test/index.php?module=McpServer&action=oauthProtectedResourceMetadata',
            $provider->getMetadataUrl(),
        );
        self::assertSame([
            'resource' => 'https://matomo.example.test/index.php?module=API&method=McpServer.mcp&format=mcp',
            'authorization_servers' => [
                'https://matomo.example.test',
            ],
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Matomo MCP Server',
        ], $provider->build());
    }

    private function createProvider(SystemSettings $settings, string $baseUrl): ProtectedResourceMetadataProvider
    {
        return new class ($settings, $baseUrl) extends ProtectedResourceMetadataProvider {
            public function __construct(
                SystemSettings $settings,
                private string $baseUrl,
            ) {
                parent::__construct($settings);
            }

            protected function getBaseUrl(): string
            {
                return rtrim($this->baseUrl, '/');
            }
        };
    }
}
