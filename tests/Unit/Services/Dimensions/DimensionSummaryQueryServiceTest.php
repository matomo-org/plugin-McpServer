<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Dimensions;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\CoreCustomDimensionsGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionSummaryQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class DimensionSummaryQueryServiceTest extends TestCase
{
    public function testGetDimensionSummariesForSiteThrowsWhenPluginIsUnavailable(): void
    {
        $gateway = $this->createMock(CoreCustomDimensionsGatewayInterface::class);
        $gateway->expects(self::never())->method('getConfiguredCustomDimensions');

        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->expects(self::once())
            ->method('isPluginActivated')
            ->with('CustomDimensions')
            ->willReturn(false);

        $service = new DimensionSummaryQueryService($gateway, $capabilityGateway);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('CustomDimensions plugin is not available.');
        $service->getDimensionSummariesForSite(7);
    }

    public function testNormalizeDimensionSummaryDataThrowsWhenFieldIsMissing(): void
    {
        $service = $this->createService();
        $data = $this->makeValidDimensionSummaryData();
        unset($data['scope']);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Dimension list item is incomplete (missing 'scope').");

        $service->normalizeDimensionSummaryData($data, 'Dimension list item');
    }

    public function testNormalizeDimensionSummaryDataThrowsWhenFieldIsNull(): void
    {
        $service = $this->createService();
        $data = $this->makeValidDimensionSummaryData();
        $data['name'] = null;

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Dimension list item is incomplete (missing 'name').");

        $service->normalizeDimensionSummaryData($data, 'Dimension list item');
    }

    public function testNormalizeDimensionSummaryDataReturnsExpectedTypedOutput(): void
    {
        $service = $this->createService();

        $dimension = $service->normalizeDimensionSummaryData(
            $this->makeValidDimensionSummaryData(),
            'Dimension list item',
        );

        self::assertSame([
            'iddimension' => 9,
            'name' => 'Customer Type',
            'scope' => 'visit',
        ], $dimension->toArray());
    }

    public function testNormalizeDimensionSummaryRowsReturnsOnlyActiveRows(): void
    {
        $service = $this->createService();
        $inactive = $this->makeValidDimensionSummaryData();
        $inactive['idcustomdimension'] = '8';
        $inactive['active'] = '0';

        $actual = $service->normalizeDimensionSummaryRows(
            [$inactive, $this->makeValidDimensionSummaryData()],
            'Dimension list data is invalid.',
            'Dimension list item',
        );

        self::assertCount(1, $actual);
        self::assertSame(9, $actual[0]->idDimension);
    }

    public function testNormalizeDimensionSummaryRowsThrowsWhenPayloadIsNotArray(): void
    {
        $service = $this->createService();

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Dimension list data is invalid.');

        $service->normalizeDimensionSummaryRows(
            'invalid',
            'Dimension list data is invalid.',
            'Dimension list item',
        );
    }

    public function testNormalizeDimensionSummaryRowsThrowsWhenRowIsNotArray(): void
    {
        $service = $this->createService();

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Dimension list data is invalid.');

        $service->normalizeDimensionSummaryRows(
            ['invalid'],
            'Dimension list data is invalid.',
            'Dimension list item',
        );
    }

    public function testNormalizeDimensionSummaryRowsReturnsOnlyDimensionRows(): void
    {
        $service = $this->createService();
        $dimension = $this->makeValidDimensionSummaryData();
        $dimension['idcustomdimension'] = '11';
        $dimension['active'] = true;

        $actual = $service->normalizeDimensionSummaryRows(
            [$dimension],
            'Dimension list data is invalid.',
            'Dimension list item',
        );

        self::assertCount(1, $actual);
        self::assertSame('visit', $actual[0]->scope);
        self::assertSame(11, $actual[0]->idDimension);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidDimensionSummaryData(): array
    {
        return [
            'idcustomdimension' => '9',
            'name' => 'Customer Type',
            'scope' => 'visit',
            'active' => '1',
        ];
    }

    private function createService(): DimensionSummaryQueryService
    {
        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->method('isPluginActivated')->willReturn(true);

        return new DimensionSummaryQueryService(
            $this->createMock(CoreCustomDimensionsGatewayInterface::class),
            $capabilityGateway,
        );
    }
}
