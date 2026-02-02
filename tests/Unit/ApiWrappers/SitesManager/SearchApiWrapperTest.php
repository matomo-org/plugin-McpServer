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
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\SearchApiWrapper;

/**
 * @group McpServer
 * @group Plugins
 */
class SearchApiWrapperTest extends TestCase
{
    public function testNormalizeSiteSummaryDataThrowsWhenFieldIsMissing(): void
    {
        $wrapper = new SearchApiWrapper();
        $data = $this->makeValidSiteSummaryData();
        unset($data['main_url']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site search item is incomplete (missing 'main_url').");

        $wrapper->normalizeSiteSummaryData($data);
    }

    public function testNormalizeSiteSummaryDataThrowsWhenFieldIsNull(): void
    {
        $wrapper = new SearchApiWrapper();
        $data = $this->makeValidSiteSummaryData();
        $data['type'] = null;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site search item is incomplete (missing 'type').");

        $wrapper->normalizeSiteSummaryData($data);
    }

    public function testNormalizeSiteSummaryDataReturnsExpectedTypedOutput(): void
    {
        $wrapper = new SearchApiWrapper();
        $data = $this->makeValidSiteSummaryData();

        $site = $wrapper->normalizeSiteSummaryData($data);

        self::assertSame([
            'idsite' => 3,
            'name' => 'Site Name',
            'main_url' => 'https://example.test',
            'type' => 'website',
        ], $site->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidSiteSummaryData(): array
    {
        return [
            'idsite' => '3',
            'name' => 'Site Name',
            'main_url' => 'https://example.test',
            'type' => 'website',
        ];
    }
}
