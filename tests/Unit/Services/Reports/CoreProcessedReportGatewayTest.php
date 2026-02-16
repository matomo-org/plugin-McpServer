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
use Piwik\Plugins\API\ProcessedReport;
use Piwik\Plugins\McpServer\Services\Reports\CoreProcessedReportGateway;

/**
 * @group McpServer
 * @group Plugins
 */
class CoreProcessedReportGatewayTest extends TestCase
{
    public function testGetReportMetadataByUniqueIdReturnsStringKeyedArray(): void
    {
        $processedReport = $this->createMock(ProcessedReport::class);
        $processedReport->expects(self::once())
            ->method('getReportMetadataByUniqueId')
            ->with(1, 'Actions_getPageUrls')
            ->willReturn(['uniqueId' => 'Actions_getPageUrls', 'module' => 'Actions']);

        $gateway = new CoreProcessedReportGateway($processedReport);
        $actual = $gateway->getReportMetadataByUniqueId(1, 'Actions_getPageUrls');

        self::assertSame('Actions_getPageUrls', $actual['uniqueId'] ?? null);
        self::assertSame('Actions', $actual['module'] ?? null);
    }

    public function testGetReportMetadataByUniqueIdRejectsInvalidPayloadShape(): void
    {
        $processedReport = $this->createMock(ProcessedReport::class);
        $processedReport->expects(self::once())
            ->method('getReportMetadataByUniqueId')
            ->willReturn(['invalid-indexed-row']);

        $gateway = new CoreProcessedReportGateway($processedReport);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report not found.');
        $gateway->getReportMetadataByUniqueId(1, 'Actions_getPageUrls');
    }

    public function testGetReportMetadataReturnsListOfStringKeyedRows(): void
    {
        $processedReport = $this->createMock(ProcessedReport::class);
        $processedReport->expects(self::once())
            ->method('getReportMetadata')
            ->with(1, 'day', false, false, false)
            ->willReturn([
                ['uniqueId' => 'Actions_getPageUrls', 'module' => 'Actions'],
                ['uniqueId' => 'VisitsSummary_get', 'module' => 'VisitsSummary'],
            ]);

        $gateway = new CoreProcessedReportGateway($processedReport);
        $actual = $gateway->getReportMetadata(1, 'day', false, false, false);

        self::assertCount(2, $actual);
        self::assertSame('Actions_getPageUrls', $actual[0]['uniqueId'] ?? null);
        self::assertSame('VisitsSummary_get', $actual[1]['uniqueId'] ?? null);
    }

    public function testGetReportMetadataRejectsInvalidTopLevelShape(): void
    {
        $processedReport = $this->createMock(ProcessedReport::class);
        $processedReport->expects(self::once())
            ->method('getReportMetadata')
            ->willReturn('invalid');

        $gateway = new CoreProcessedReportGateway($processedReport);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report metadata data is invalid.');
        $gateway->getReportMetadata(1, 'day', false, false, false);
    }

    public function testGetReportMetadataRejectsInvalidRowShape(): void
    {
        $processedReport = $this->createMock(ProcessedReport::class);
        $processedReport->expects(self::once())
            ->method('getReportMetadata')
            ->willReturn([
                ['uniqueId' => 'Actions_getPageUrls'],
                ['invalid-indexed-row'],
            ]);

        $gateway = new CoreProcessedReportGateway($processedReport);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report metadata data is invalid.');
        $gateway->getReportMetadata(1, 'day', false, false, false);
    }
}
