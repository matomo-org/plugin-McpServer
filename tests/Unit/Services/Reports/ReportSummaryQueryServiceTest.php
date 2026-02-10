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
use Piwik\Plugins\McpServer\Services\Reports\ReportSummaryQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportSummaryQueryServiceTest extends TestCase
{
    public function testNormalizeReportSummaryDataThrowsWhenFieldIsMissing(): void
    {
        $service = new ReportSummaryQueryService();
        $data = $this->makeValidReportSummaryData();
        unset($data['name']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Report list item is incomplete (missing 'name').");

        $service->normalizeReportSummaryData($data, 'Report list item');
    }

    public function testNormalizeReportSummaryDataThrowsWhenFieldIsNull(): void
    {
        $service = new ReportSummaryQueryService();
        $data = $this->makeValidReportSummaryData();
        $data['category'] = null;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Report list item is incomplete (missing 'category').");

        $service->normalizeReportSummaryData($data, 'Report list item');
    }

    public function testNormalizeReportSummaryDataReturnsExpectedTypedOutput(): void
    {
        $service = new ReportSummaryQueryService();

        $report = $service->normalizeReportSummaryData(
            $this->makeValidReportSummaryData(),
            'Report list item'
        );

        self::assertSame([
            'uniqueId' => 'Actions_getPageUrls',
            'module' => 'Actions',
            'action' => 'getPageUrls',
            'name' => 'Page URLs',
            'category' => 'Actions',
            'parameters' => ['idGoal' => '1'],
        ], $report->toArray());
    }

    public function testNormalizeReportSummaryDataDefaultsMissingParametersToEmptyObjectArray(): void
    {
        $service = new ReportSummaryQueryService();
        $data = $this->makeValidReportSummaryData();
        unset($data['parameters']);

        $report = $service->normalizeReportSummaryData($data, 'Report list item');

        self::assertSame([], $report->parameters);
    }

    public function testNormalizeReportSummaryDataRejectsNonArrayParameters(): void
    {
        $service = new ReportSummaryQueryService();
        $data = $this->makeValidReportSummaryData();
        $data['parameters'] = 'not-an-object';

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Report list item is invalid (field 'parameters').");

        $service->normalizeReportSummaryData($data, 'Report list item');
    }

    public function testNormalizeReportSummaryDataRejectsIndexedArrayParameters(): void
    {
        $service = new ReportSummaryQueryService();
        $data = $this->makeValidReportSummaryData();
        $data['parameters'] = ['idGoal'];

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Report list item is invalid (field 'parameters').");

        $service->normalizeReportSummaryData($data, 'Report list item');
    }

    public function testNormalizeReportSummaryRowsThrowsWhenPayloadIsNotArray(): void
    {
        $service = new ReportSummaryQueryService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report list data is invalid.');

        $service->normalizeReportSummaryRows(
            'invalid',
            'Report list data is invalid.',
            'Report list item'
        );
    }

    public function testNormalizeReportSummaryRowsThrowsWhenRowIsNotArray(): void
    {
        $service = new ReportSummaryQueryService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report list data is invalid.');

        $service->normalizeReportSummaryRows(
            ['invalid'],
            'Report list data is invalid.',
            'Report list item'
        );
    }

    public function testNormalizeReportSummaryRowsExcludesSubtableRows(): void
    {
        $service = new ReportSummaryQueryService();

        $subtable = $this->makeValidReportSummaryData();
        $subtable['uniqueId'] = 'Actions_getPageUrlsSubtable';
        $subtable['isSubtableReport'] = true;

        $actual = $service->normalizeReportSummaryRows(
            [$subtable, $this->makeValidReportSummaryData()],
            'Report list data is invalid.',
            'Report list item'
        );

        self::assertCount(1, $actual);
        self::assertSame('Actions_getPageUrls', $actual[0]->uniqueId);
    }

    public function testNormalizeReportSummaryRowsExcludesSubtableRowsUsingLegacyPluralField(): void
    {
        $service = new ReportSummaryQueryService();

        $subtable = $this->makeValidReportSummaryData();
        $subtable['uniqueId'] = 'Actions_getPageUrlsSubtable';
        $subtable['isSubtableReports'] = '1';

        $actual = $service->normalizeReportSummaryRows(
            [$subtable, $this->makeValidReportSummaryData()],
            'Report list data is invalid.',
            'Report list item'
        );

        self::assertCount(1, $actual);
        self::assertSame('Actions_getPageUrls', $actual[0]->uniqueId);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidReportSummaryData(): array
    {
        return [
            'uniqueId' => 'Actions_getPageUrls',
            'module' => 'Actions',
            'action' => 'getPageUrls',
            'name' => 'Page URLs',
            'category' => 'Actions',
            'parameters' => ['idGoal' => '1'],
        ];
    }
}
