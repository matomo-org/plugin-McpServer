<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

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

    private int $idSite = 0;
    private string $expectedTimezoneName = '';
    private string $expectedCurrencyName = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Test Site',
            'https://example.test',
        );

        SitesManagerApi::getInstance()->updateSite(
            idSite: $this->idSite,
            siteSearch: 1,
            timezone: self::TEST_TIMEZONE,
            currency: self::TEST_CURRENCY,
            type: 'website',
        );

        $site = SitesManagerApi::getInstance()->getSiteFromId($this->idSite);
        $timezoneName = $site['timezone_name'] ?? null;
        $currencyName = $site['currency_name'] ?? null;
        self::assertIsString($timezoneName);
        self::assertIsString($currencyName);
        self::assertNotSame('', $timezoneName);
        self::assertNotSame('', $currencyName);

        $this->expectedTimezoneName = $timezoneName;
        $this->expectedCurrencyName = $currencyName;
    }

    public function testReturnsExpectedContent(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SiteGet::TOOL_NAME,
            ['idSite' => $this->idSite],
            __METHOD__,
        );
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
        ], $content);
    }

    public function testReturnsErrorForMissingSite(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SiteGet::TOOL_NAME,
            ['idSite' => 999999],
            'Site not found or access denied.',
            __METHOD__,
        );
    }

    public function testReturnsErrorForSiteWithoutViewAccess(): void
    {
        McpAuthTestHelper::asNoAccessUser(function (): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            McpTestHelper::callToolAndAssertError(
                $server,
                $sessionId,
                SiteGet::TOOL_NAME,
                ['idSite' => $this->idSite],
                'Site not found or access denied.',
                __METHOD__,
            );
        });
    }

    public function testReturnsInvalidParamsErrorForInvalidIdSite(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            SiteGet::TOOL_NAME,
            ['idSite' => 0],
            __METHOD__,
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SiteGet::TOOL_NAME . "':",
            $message->message,
        );
        self::assertStringContainsString('idSite', $message->message);
    }
}
