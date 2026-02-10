<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Piwik\Plugins\McpServer\McpTools\SiteGet;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SiteGetTest extends IntegrationTestCase
{
    private const TEST_TIMEZONE = 'UTC+2';
    private const TEST_CURRENCY = 'EUR';

    private int $idSite;
    private string $expectedTimezoneName;
    private string $expectedCurrencyName;

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Test Site',
            'https://example.test'
        );

        SitesManagerApi::getInstance()->updateSite(
            idSite: $this->idSite,
            siteSearch: 1,
            timezone: self::TEST_TIMEZONE,
            currency: self::TEST_CURRENCY,
            type: 'website'
        );

        $site = SitesManagerApi::getInstance()->getSiteFromId($this->idSite);
        self::assertArrayHasKey('timezone_name', $site);
        self::assertArrayHasKey('currency_name', $site);
        self::assertIsString($site['timezone_name']);
        self::assertIsString($site['currency_name']);
        self::assertNotSame('', $site['timezone_name']);
        self::assertNotSame('', $site['currency_name']);

        $this->expectedTimezoneName = $site['timezone_name'];
        $this->expectedCurrencyName = $site['currency_name'];
    }

    public function testReturnsExpectedContent(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteGet::TOOL_NAME,
            ['idSite' => $this->idSite],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);

        self::assertSame([
            'idsite' => $this->idSite,
            'name' => 'MCP Test Site',
            'main_url' => 'https://example.test',
            'timezone' => self::TEST_TIMEZONE,
            'timezone_name' => $this->expectedTimezoneName,
            'currency' => self::TEST_CURRENCY,
            'currency_name' => $this->expectedCurrencyName,
            'ecommerce' => false,
            'sitesearch' => true,
            'type' => 'website',
        ], $result->structuredContent);
    }

    public function testReturnsErrorForMissingSite(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteGet::TOOL_NAME,
            ['idSite' => 999999],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertTrue($result->isError);
        self::assertNotEmpty($result->content);
        self::assertSame('Site not found or access denied.', $result->content[0]->text ?? null);
    }

    public function testReturnsErrorForSiteWithoutViewAccess(): void
    {
        McpAuthTestHelper::asNoAccessUser(function (): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            $payload = McpTestHelper::makeCallToolRequest(
                SiteGet::TOOL_NAME,
                ['idSite' => $this->idSite],
                __METHOD__
            );

            $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
            $message = McpTestHelper::decodeResponse($response);
            $result = McpTestHelper::parseCallTool($message);

            self::assertTrue($result->isError);
            self::assertNotEmpty($result->content);
            self::assertSame('Site not found or access denied.', $result->content[0]->text ?? null);
        });
    }

    public function testReturnsInvalidParamsErrorForInvalidIdSite(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteGet::TOOL_NAME,
            ['idSite' => 0],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SiteGet::TOOL_NAME . "':",
            $message->message
        );
        self::assertStringContainsString('idSite', $message->message);
    }
}
