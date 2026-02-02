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
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\ApiWrapper;
use PHPUnit\Framework\TestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiWrapperTest extends TestCase
{
    public function testNormalizeSiteDataThrowsWhenFieldIsMissing(): void
    {
        $wrapper = new ApiWrapper();
        $data = $this->makeValidSiteData();
        unset($data['currency_name']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site data is incomplete (missing 'currency_name').");

        $wrapper->normalizeSiteData($data);
    }

    public function testNormalizeSiteDataThrowsWhenFieldIsNull(): void
    {
        $wrapper = new ApiWrapper();
        $data = $this->makeValidSiteData();
        $data['timezone_name'] = null;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site data is incomplete (missing 'timezone_name').");

        $wrapper->normalizeSiteData($data);
    }

    public function testNormalizeSiteDataReturnsExpectedTypedOutput(): void
    {
        $wrapper = new ApiWrapper();
        $data = $this->makeValidSiteData();

        $site = $wrapper->normalizeSiteData($data);

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
}
