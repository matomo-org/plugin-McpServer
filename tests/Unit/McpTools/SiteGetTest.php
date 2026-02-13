<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Sites\SiteDetailRecord;
use Piwik\Plugins\McpServer\McpTools\SiteGet;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SiteGetTest extends TestCase
{
    public function testGetReturnsRecordFromApiWrapper(): void
    {
        $wrapper = new class () implements SiteDetailQueryServiceInterface {
            public function getSiteDetailFromId(int $idSite): SiteDetailRecord
            {
                return new SiteDetailRecord(
                    idSite: $idSite,
                    name: 'Site Name',
                    mainUrl: 'https://example.test',
                    timezone: 'UTC+2',
                    timezoneName: 'UTC+2',
                    currency: 'EUR',
                    currencyName: 'Euro',
                    ecommerce: false,
                    siteSearch: true,
                    type: 'website'
                );
            }
        };

        $actual = (new SiteGet($wrapper))->get(4);

        self::assertSame([
            'idsite' => 4,
            'name' => 'Site Name',
            'main_url' => 'https://example.test',
            'timezone' => 'UTC+2',
            'timezone_name' => 'UTC+2',
            'currency' => 'EUR',
            'currency_name' => 'Euro',
            'ecommerce' => false,
            'sitesearch' => true,
            'type' => 'website',
        ], $actual);
    }

    public function testGetPropagatesMalformedUpstreamPayloadErrorFromWrapper(): void
    {
        $wrapper = new class () implements SiteDetailQueryServiceInterface {
            public function getSiteDetailFromId(int $idSite): SiteDetailRecord
            {
                throw new ToolCallException("Site data is incomplete (missing 'currency_name').");
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site data is incomplete (missing 'currency_name').");

        (new SiteGet($wrapper))->get(4);
    }
}
