<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Segments;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\CoreSegmentEditorGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\Services\Segments\SegmentSummaryQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class SegmentSummaryQueryServiceTest extends TestCase
{
    public function testGetSegmentSummariesForSiteThrowsWhenPluginIsUnavailable(): void
    {
        $gateway = $this->createMock(CoreSegmentEditorGatewayInterface::class);
        $gateway->expects(self::never())->method('getAll');

        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->expects(self::once())
            ->method('isPluginActivated')
            ->with('SegmentEditor')
            ->willReturn(false);

        $service = new SegmentSummaryQueryService($gateway, $capabilityGateway);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('SegmentEditor plugin is not available.');
        $service->getSegmentSummariesForSite(9);
    }

    public function testNormalizeSegmentSummaryDataThrowsWhenFieldIsMissing(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSegmentSummaryData();
        unset($data['definition']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Segment list item is incomplete (missing 'definition').");

        $service->normalizeSegmentSummaryData($data, 'Segment list item');
    }

    public function testNormalizeSegmentSummaryDataThrowsWhenFieldIsNull(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSegmentSummaryData();
        $data['name'] = null;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Segment list item is incomplete (missing 'name').");

        $service->normalizeSegmentSummaryData($data, 'Segment list item');
    }

    public function testNormalizeSegmentSummaryDataMapsAllSitesToNullIdSite(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSegmentSummaryData();

        $segment = $service->normalizeSegmentSummaryData($data, 'Segment list item');

        self::assertSame([
            'idsegment' => 3,
            'name' => 'Segment Name',
            'definition' => 'countryCode==de',
            'idsite' => null,
        ], $segment->toArray());
    }

    public function testNormalizeSegmentSummaryRowsThrowsWhenPayloadIsNotArray(): void
    {
        $service = $this->createService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Segment list data is invalid.');

        $service->normalizeSegmentSummaryRows(
            'invalid',
            'Segment list data is invalid.',
            'Segment list item',
        );
    }

    public function testNormalizeSegmentSummaryRowsThrowsWhenRowIsNotArray(): void
    {
        $service = $this->createService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Segment list data is invalid.');

        $service->normalizeSegmentSummaryRows(
            ['invalid'],
            'Segment list data is invalid.',
            'Segment list item',
        );
    }

    public function testNormalizeSegmentSummaryRowsReturnsNormalizedRows(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSegmentSummaryData();
        $data['enable_only_idsite'] = '7';

        $actual = $service->normalizeSegmentSummaryRows(
            [$data],
            'Segment list data is invalid.',
            'Segment list item',
        );

        self::assertCount(1, $actual);
        self::assertSame([
            'idsegment' => 3,
            'name' => 'Segment Name',
            'definition' => 'countryCode==de',
            'idsite' => 7,
        ], $actual[0]->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidSegmentSummaryData(): array
    {
        return [
            'idsegment' => '3',
            'name' => 'Segment Name',
            'definition' => 'countryCode==de',
            'enable_only_idsite' => '0',
        ];
    }

    private function createService(): SegmentSummaryQueryService
    {
        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->method('isPluginActivated')->willReturn(true);

        return new SegmentSummaryQueryService(
            $this->createMock(CoreSegmentEditorGatewayInterface::class),
            $capabilityGateway,
        );
    }
}
