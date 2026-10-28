<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Api;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Api\ProtectedResourceMetadata;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @group McpServer
 * @group Plugins
 */
class ProtectedResourceMetadataTest extends TestCase
{
    private const SERVER_KEYS = ['HTTP_HOST', 'HTTPS', 'SCRIPT_NAME', 'REQUEST_URI'];

    /** @var array<string, mixed> */
    private array $originalServer = [];

    public function setUp(): void
    {
        parent::setUp();

        foreach (self::SERVER_KEYS as $key) {
            if (array_key_exists($key, $_SERVER)) {
                $this->originalServer[$key] = $_SERVER[$key];
            }
        }
    }

    public function tearDown(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            unset($_SERVER[$key]);
        }

        foreach ($this->originalServer as $key => $value) {
            $_SERVER[$key] = $value;
        }

        parent::tearDown();
    }

    public function testBuildDescribesMcpEndpointAndNamesMatomoAsAuthorizationServer(): void
    {
        $this->setCurrentRequest('/analytics/index.php');

        self::assertSame(
            [
                'resource' => 'https://matomo.example/analytics/index.php?module=API&method=McpServer.mcp&format=mcp',
                'authorization_servers' => ['https://matomo.example/analytics'],
                'bearer_methods_supported' => ['header'],
            ],
            $this->createMetadata(true)->build(),
        );
    }

    public function testChallengeAdvertisesMetadataUrlWhenOAuth2IsActivated(): void
    {
        $this->setCurrentRequest('/index.php');

        self::assertSame(
            'Bearer realm="mcp", resource_metadata="'
                . 'https://matomo.example/index.php?module=McpServer&action=protectedResourceMetadata"',
            $this->createMetadata(true)->getWwwAuthenticateHeader(),
        );
    }

    public function testChallengeStaysPlainWhenOAuth2IsNotActivated(): void
    {
        $this->setCurrentRequest('/index.php');

        $metadata = $this->createMetadata(false);

        self::assertFalse($metadata->isAvailable());
        self::assertSame('Bearer realm="mcp"', $metadata->getWwwAuthenticateHeader());
    }

    private function createMetadata(bool $isOAuth2Enabled): ProtectedResourceMetadata
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('isOAuth2PluginEnabled')->willReturn($isOAuth2Enabled);

        return new ProtectedResourceMetadata($settings);
    }

    private function setCurrentRequest(string $scriptName): void
    {
        $_SERVER['HTTP_HOST'] = 'matomo.example';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SCRIPT_NAME'] = $scriptName;
        $_SERVER['REQUEST_URI'] = $scriptName . '?module=API&method=McpServer.mcp&format=mcp';
    }
}
