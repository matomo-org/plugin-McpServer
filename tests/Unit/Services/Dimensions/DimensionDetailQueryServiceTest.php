<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Dimensions;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionDetailQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class DimensionDetailQueryServiceTest extends TestCase
{
    public function testNormalizeDimensionDetailDataThrowsWhenFieldIsMissing(): void
    {
        $service = new DimensionDetailQueryService();
        $data = $this->makeValidDimensionDetailData();
        unset($data['name']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Dimension data is incomplete (missing 'name').");

        $service->normalizeDimensionDetailData($data, 'Dimension data');
    }

    public function testNormalizeDimensionDetailDataThrowsWhenFieldIsInvalid(): void
    {
        $service = new DimensionDetailQueryService();
        $data = $this->makeValidDimensionDetailData();
        $data['case_sensitive'] = 'invalid';

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Dimension data is invalid (field 'case_sensitive').");

        $service->normalizeDimensionDetailData($data, 'Dimension data');
    }

    public function testNormalizeDimensionDetailDataReturnsExpectedTypedOutput(): void
    {
        $service = new DimensionDetailQueryService();

        $dimension = $service->normalizeDimensionDetailData($this->makeValidDimensionDetailData(), 'Dimension data');

        self::assertSame([
            'iddimension' => 3,
            'idsite' => 5,
            'name' => 'Customer Tier',
            'index' => 2,
            'scope' => 'visit',
            'active' => true,
            'case_sensitive' => false,
            'extractions' => [
                [
                    'dimension' => 'url',
                    'pattern' => 'customer=(.*)',
                ],
            ],
        ], $dimension->toArray());
    }

    public function testNormalizeDimensionDetailDataRejectsInvalidExtractionsShape(): void
    {
        $service = new DimensionDetailQueryService();
        $data = $this->makeValidDimensionDetailData();
        $data['extractions'] = ['invalid'];

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Dimension data is invalid (field 'extractions').");

        $service->normalizeDimensionDetailData($data, 'Dimension data');
    }

    public function testNormalizeDimensionDetailDataSupportsEmptyExtractions(): void
    {
        $service = new DimensionDetailQueryService();
        $data = $this->makeValidDimensionDetailData();
        $data['extractions'] = [];

        $dimension = $service->normalizeDimensionDetailData($data, 'Dimension data');

        self::assertSame([], $dimension->extractions);
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidDimensionDetailData(): array
    {
        return [
            'idcustomdimension' => '3',
            'idsite' => '5',
            'name' => 'Customer Tier',
            'index' => '2',
            'scope' => 'visit',
            'active' => '1',
            'case_sensitive' => '0',
            'extractions' => [
                [
                    'dimension' => 'url',
                    'pattern' => 'customer=(.*)',
                ],
            ],
        ];
    }
}
