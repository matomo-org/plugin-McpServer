<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Sites;

use PHPUnit\Framework\TestCase;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\CoreSitesManagerGatewayInterface;
use Piwik\Plugins\McpServer\Services\Sites\SiteDetailQueryService;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;

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

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Site data is incomplete (missing 'currency_name').");

        $service->normalizeSiteDetailData($data);
    }

    public function testNormalizeSiteDetailDataThrowsWhenFieldIsNull(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSiteData();
        $data['timezone_name'] = null;

        $this->expectException(McpToolCallException::class);
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

    public function testGetSiteDetailFromIdMapsMessageBasedAccessFailureToNotFoundOrAccessDenied(): void
    {
        $gateway = $this->createMock(CoreSitesManagerGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('getSiteFromId')
            ->with(3)
            ->willThrowException(new \RuntimeException('CheckUserHasViewAccess failed'));

        $service = new SiteDetailQueryService($gateway);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Site not found or access denied.');
        $service->getSiteDetailFromId(3);
    }

    public function testGetSiteDetailFromIdMapsTypeBasedAccessFailureWithEmptyMessageToNotFoundOrAccessDenied(): void
    {
        foreach ([new NoAccessException(''), new AccessDeniedLikeException('')] as $exception) {
            $gateway = $this->createMock(CoreSitesManagerGatewayInterface::class);
            $gateway->method('getSiteFromId')->willThrowException($exception);

            $service = new SiteDetailQueryService($gateway);

            try {
                $service->getSiteDetailFromId(3);
                self::fail('Expected McpToolCallException was not thrown for ' . get_class($exception));
            } catch (McpToolCallException $e) {
                self::assertSame('Site not found or access denied.', $e->getMessage());
            }
        }
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
