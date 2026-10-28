<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\Common;
use Piwik\Plugins\McpServer\Controller;
use Piwik\Plugins\McpServer\Support\Api\ProtectedResourceMetadata;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @group McpServer
 * @group Plugins
 */
class ControllerTest extends TestCase
{
    private const SERVER_KEYS = ['HTTP_HOST', 'HTTPS', 'SCRIPT_NAME', 'REQUEST_URI', 'REQUEST_METHOD'];

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

        $_SERVER['HTTP_HOST'] = 'matomo.example';
        $_SERVER['HTTPS'] = 'on';
        $_SERVER['SCRIPT_NAME'] = '/index.php';
        $_SERVER['REQUEST_URI'] = '/index.php?module=McpServer&action=protectedResourceMetadata';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        Common::$headersSentInTests = [];
    }

    public function tearDown(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            unset($_SERVER[$key]);
        }

        foreach ($this->originalServer as $key => $value) {
            $_SERVER[$key] = $value;
        }

        Common::$headersSentInTests = [];

        parent::tearDown();
    }

    public function testProtectedResourceMetadataServesCacheableJsonWhenOAuth2IsActivated(): void
    {
        Common::sendHeader('Set-Cookie: MATOMO_SESSID=abc');

        $body = $this->createController(true)->protectedResourceMetadata();

        self::assertSame(
            '{"resource":"https://matomo.example/index.php?module=API&method=McpServer.mcp&format=mcp",'
                . '"authorization_servers":["https://matomo.example"],'
                . '"bearer_methods_supported":["header"]}',
            $body,
        );
        self::assertSame('application/json; charset=utf-8', Common::getSentHeader('Content-Type'));
        self::assertSame('public, max-age=3600', Common::getSentHeader('Cache-Control'));
        self::assertSame('', Common::getSentHeader('Set-Cookie'));
    }

    public function testProtectedResourceMetadataSendsNoBodyForHead(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'HEAD';

        self::assertSame('', $this->createController(true)->protectedResourceMetadata());
        self::assertSame('public, max-age=3600', Common::getSentHeader('Cache-Control'));
    }

    public function testProtectedResourceMetadataIsNotFoundWhenOAuth2IsNotActivated(): void
    {
        $body = $this->createController(false)->protectedResourceMetadata();

        self::assertSame('{"error":"Not found."}', $body);
        self::assertArrayHasKey('HTTP/1.1 404 Not Found', Common::$headersSentInTests);
        self::assertSame('no-store', Common::getSentHeader('Cache-Control'));
    }

    public function testProtectedResourceMetadataRejectsOtherMethods(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $body = $this->createController(true)->protectedResourceMetadata();

        self::assertSame('{"error":"Method not allowed."}', $body);
        self::assertArrayHasKey('HTTP/1.1 405 Method Not Allowed', Common::$headersSentInTests);
        self::assertSame('GET, HEAD', Common::getSentHeader('Allow'));
        self::assertSame('no-store', Common::getSentHeader('Cache-Control'));
    }

    private function createController(bool $isOAuth2Enabled): Controller
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('isOAuth2PluginEnabled')->willReturn($isOAuth2Enabled);

        return new Controller($settings, new ProtectedResourceMetadata($settings));
    }
}
