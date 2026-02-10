<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Reports;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Services\Reports\ReportMetadataQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportMetadataQueryServiceTest extends TestCase
{
    public function testNormalizeReportMetadataDataThrowsWhenFieldIsMissing(): void
    {
        $service = new ReportMetadataQueryService();
        $data = $this->makeValidReportMetadataData();
        unset($data['name']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Report metadata item is incomplete (missing 'name').");

        $service->normalizeReportMetadataData($data, 'Report metadata item');
    }

    public function testNormalizeReportMetadataDataRejectsIndexedArrayParameters(): void
    {
        $service = new ReportMetadataQueryService();
        $data = $this->makeValidReportMetadataData();
        $data['parameters'] = ['idGoal'];

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Report metadata item is invalid (field 'parameters').");

        $service->normalizeReportMetadataData($data, 'Report metadata item');
    }

    public function testNormalizeReportMetadataDataReturnsExpectedTypedOutput(): void
    {
        $service = new ReportMetadataQueryService();

        $record = $service->normalizeReportMetadataData(
            $this->makeValidReportMetadataData(),
            'Report metadata item'
        );

        $actual = $record->toArray();
        self::assertSame('Actions_getPageUrls', $actual['uniqueId']);
        self::assertSame('Actions', $actual['module']);
        self::assertSame('getPageUrls', $actual['action']);
        self::assertSame('Page URLs', $actual['name']);
        self::assertSame('Actions', $actual['category']);
        self::assertSame(['idGoal' => '1'], $actual['parameters']);
        self::assertIsArray($actual['metadata']);
        self::assertSame('Actions_getPageUrls', $actual['metadata']['uniqueId'] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidReportMetadataData(): array
    {
        return [
            'uniqueId' => 'Actions_getPageUrls',
            'module' => 'Actions',
            'action' => 'getPageUrls',
            'name' => 'Page URLs',
            'category' => 'Actions',
            'parameters' => ['idGoal' => '1'],
            'metrics' => ['nb_visits' => 'Visits'],
        ];
    }
}
