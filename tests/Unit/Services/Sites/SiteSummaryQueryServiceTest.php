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
use Piwik\Plugins\McpServer\Services\Sites\SiteSummaryQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class SiteSummaryQueryServiceTest extends TestCase
{
    public function testNormalizeSiteSummaryDataThrowsWhenFieldIsMissing(): void
    {
        $service = new SiteSummaryQueryService();
        $data = $this->makeValidSiteSummaryData();
        unset($data['main_url']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site list item is incomplete (missing 'main_url').");

        $service->normalizeSiteSummaryData($data, 'Site list item');
    }

    public function testNormalizeSiteSummaryDataThrowsWhenFieldIsNull(): void
    {
        $service = new SiteSummaryQueryService();
        $data = $this->makeValidSiteSummaryData();
        $data['type'] = null;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Site search item is incomplete (missing 'type').");

        $service->normalizeSiteSummaryData($data, 'Site search item');
    }

    public function testNormalizeSiteSummaryDataReturnsExpectedTypedOutput(): void
    {
        $service = new SiteSummaryQueryService();
        $data = $this->makeValidSiteSummaryData();

        $site = $service->normalizeSiteSummaryData($data, 'Site list item');

        self::assertSame([
            'idsite' => 3,
            'name' => 'Site Name',
            'main_url' => 'https://example.test',
            'type' => 'website',
        ], $site->toArray());
    }

    public function testNormalizeSiteSummaryRowsThrowsWhenPayloadIsNotArray(): void
    {
        $service = new SiteSummaryQueryService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site list data is invalid.');

        $service->normalizeSiteSummaryRows(
            'invalid',
            'Site list data is invalid.',
            'Site list item'
        );
    }

    public function testNormalizeSiteSummaryRowsThrowsWhenRowIsNotArray(): void
    {
        $service = new SiteSummaryQueryService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site search data is invalid.');

        $service->normalizeSiteSummaryRows(
            ['invalid'],
            'Site search data is invalid.',
            'Site search item'
        );
    }

    public function testNormalizeSiteSummaryRowsReturnsNormalizedRows(): void
    {
        $service = new SiteSummaryQueryService();

        $actual = $service->normalizeSiteSummaryRows(
            [$this->makeValidSiteSummaryData()],
            'Site search data is invalid.',
            'Site search item'
        );

        self::assertCount(1, $actual);
        self::assertSame([
            'idsite' => 3,
            'name' => 'Site Name',
            'main_url' => 'https://example.test',
            'type' => 'website',
        ], $actual[0]->toArray());
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
