<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Sites;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Services\Sites\CoreSitesManagerGateway;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;

/**
 * @group McpServer
 * @group Plugins
 */
class CoreSitesManagerGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        SitesManagerApi::unsetInstance();
        parent::tearDown();
    }

    public function testGetSitesWithMinimumAccessReturnsTypedList(): void
    {
        $api = $this->createMock(SitesManagerApi::class);
        $api->expects(self::once())
            ->method('getSitesWithMinimumAccess')
            ->with('view', 'site', 2)
            ->willReturn([
                ['idsite' => '1', 'name' => 'Site Alpha'],
                ['idsite' => '2', 'name' => 'Site Beta'],
            ]);
        SitesManagerApi::setSingletonInstance($api);

        $gateway = new CoreSitesManagerGateway();
        $result = $gateway->getSitesWithMinimumAccess('view', 'site', 2);

        self::assertCount(2, $result);
        self::assertSame('Site Alpha', $result[0]['name'] ?? null);
        self::assertSame('Site Beta', $result[1]['name'] ?? null);
    }

    public function testGetSitesWithMinimumAccessRejectsInvalidTopLevelPayload(): void
    {
        $api = $this->createMock(SitesManagerApi::class);
        $api->expects(self::once())
            ->method('getSitesWithMinimumAccess')
            ->willReturn(['unexpected' => 'shape']);
        SitesManagerApi::setSingletonInstance($api);

        $gateway = new CoreSitesManagerGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site list data is invalid.');
        $gateway->getSitesWithMinimumAccess('view', '', null);
    }

    public function testGetSitesWithMinimumAccessRejectsInvalidRowPayload(): void
    {
        $api = $this->createMock(SitesManagerApi::class);
        $api->expects(self::once())
            ->method('getSitesWithMinimumAccess')
            ->willReturn([
                ['idsite' => '1', 'name' => 'Site Alpha'],
                ['invalid-row'],
            ]);
        SitesManagerApi::setSingletonInstance($api);

        $gateway = new CoreSitesManagerGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site list data is invalid.');
        $gateway->getSitesWithMinimumAccess('view', '', null);
    }

    public function testGetSiteFromIdReturnsTypedRow(): void
    {
        $api = $this->createMock(SitesManagerApi::class);
        $api->expects(self::once())
            ->method('getSiteFromId')
            ->with(4)
            ->willReturn(['idsite' => '4', 'name' => 'Site Detail']);
        SitesManagerApi::setSingletonInstance($api);

        $gateway = new CoreSitesManagerGateway();
        $result = $gateway->getSiteFromId(4);

        self::assertSame('Site Detail', $result['name'] ?? null);
    }

    public function testGetSiteFromIdRejectsInvalidRowPayload(): void
    {
        $api = $this->createMock(SitesManagerApi::class);
        $api->expects(self::once())
            ->method('getSiteFromId')
            ->willReturn(['invalid-row']);
        SitesManagerApi::setSingletonInstance($api);

        $gateway = new CoreSitesManagerGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site data is invalid.');
        $gateway->getSiteFromId(4);
    }
}
