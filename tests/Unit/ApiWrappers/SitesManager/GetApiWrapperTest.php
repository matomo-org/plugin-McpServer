<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\ApiWrappers\SitesManager;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\GetApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteDetailRecord;

/**
 * @group McpServer
 * @group Plugins
 */
class GetApiWrapperTest extends TestCase
{
    public function testGetSiteDetailFromIdDelegatesToQueryService(): void
    {
        $queryService = new class () implements SiteDetailQueryServiceInterface {
            public int $receivedIdSite = 0;

            public function getSiteDetailFromId(int $idSite): SiteDetailRecord
            {
                $this->receivedIdSite = $idSite;

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

        $wrapper = new GetApiWrapper($queryService);
        $site = $wrapper->getSiteDetailFromId(7);

        self::assertSame(7, $queryService->receivedIdSite);
        self::assertSame(7, $site->idSite);
        self::assertSame('Site Name', $site->name);
    }

    public function testGetSiteDetailFromIdPropagatesToolCallExceptionFromQueryService(): void
    {
        $queryService = new class () implements SiteDetailQueryServiceInterface {
            public function getSiteDetailFromId(int $idSite): SiteDetailRecord
            {
                throw new ToolCallException('Site retrieval failed.');
            }
        };

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site retrieval failed.');

        (new GetApiWrapper($queryService))->getSiteDetailFromId(7);
    }
}
