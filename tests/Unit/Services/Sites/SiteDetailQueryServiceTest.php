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
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\CoreSitesManagerGatewayInterface;
use Piwik\Plugins\McpServer\Services\Sites\SiteDetailQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class SiteDetailQueryServiceTest extends TestCase
{
    public function testNormalizeSiteDetailDataThrowsWhenFieldIsMissing(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSiteData();
        unset($data['currency_name']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site data is incomplete (missing 'currency_name').");

        $service->normalizeSiteDetailData($data);
    }

    public function testNormalizeSiteDetailDataThrowsWhenFieldIsNull(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSiteData();
        $data['timezone_name'] = null;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site data is incomplete (missing 'timezone_name').");

        $service->normalizeSiteDetailData($data);
    }

    public function testNormalizeSiteDetailDataReturnsExpectedTypedOutput(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSiteData();

        $site = $service->normalizeSiteDetailData($data);

        self::assertSame([
            'idsite' => 3,
            'name' => 'Site Name',
            'main_url' => 'https://example.test',
            'timezone' => 'UTC+2',
            'timezone_name' => 'UTC+2',
            'currency' => 'EUR',
            'currency_name' => 'Euro',
            'ecommerce' => false,
            'sitesearch' => true,
            'type' => 'website',
        ], $site->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidSiteData(): array
    {
        return [
            'idsite' => '3',
            'name' => 'Site Name',
            'main_url' => 'https://example.test',
            'timezone' => 'UTC+2',
            'timezone_name' => 'UTC+2',
            'currency' => 'EUR',
            'currency_name' => 'Euro',
            'ecommerce' => 0,
            'sitesearch' => 1,
            'type' => 'website',
        ];
    }

    private function createService(): SiteDetailQueryService
    {
        return new SiteDetailQueryService($this->createMock(CoreSitesManagerGatewayInterface::class));
    }
}
