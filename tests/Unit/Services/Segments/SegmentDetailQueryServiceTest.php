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
use Piwik\Plugins\McpServer\Services\Segments\SegmentDetailQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class SegmentDetailQueryServiceTest extends TestCase
{
    public function testNormalizeSegmentDetailDataThrowsWhenFieldIsMissing(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSegmentDetailData();
        unset($data['login']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Segment detail item is incomplete (missing 'login').");

        $service->normalizeSegmentDetailData($data, 'Segment detail item');
    }

    public function testNormalizeSegmentDetailDataThrowsWhenFieldIsInvalid(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSegmentDetailData();
        $data['auto_archive'] = 'invalid';

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Segment detail item is invalid (field 'auto_archive').");

        $service->normalizeSegmentDetailData($data, 'Segment detail item');
    }

    public function testNormalizeSegmentDetailDataReturnsExpectedTypedOutput(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSegmentDetailData();

        $segment = $service->normalizeSegmentDetailData($data, 'Segment detail item');

        self::assertSame([
            'idsegment' => 3,
            'name' => 'Segment Name',
            'definition' => 'countryCode==de',
            'idsite' => null,
            'auto_archive' => true,
            'enabled_all_users' => false,
            'login' => 'superUserLogin',
        ], $segment->toArray());
    }

    public function testNormalizeSegmentDetailRowsThrowsWhenPayloadIsNotArray(): void
    {
        $service = $this->createService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Segment detail data is invalid.');

        $service->normalizeSegmentDetailRows(
            'invalid',
            'Segment detail data is invalid.',
            'Segment detail item'
        );
    }

    public function testNormalizeSegmentDetailRowsThrowsWhenRowIsNotArray(): void
    {
        $service = $this->createService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Segment detail data is invalid.');

        $service->normalizeSegmentDetailRows(
            ['invalid'],
            'Segment detail data is invalid.',
            'Segment detail item'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidSegmentDetailData(): array
    {
        return [
            'idsegment' => '3',
            'name' => 'Segment Name',
            'definition' => 'countryCode==de',
            'enable_only_idsite' => '0',
            'auto_archive' => '1',
            'enable_all_users' => '0',
            'login' => 'superUserLogin',
        ];
    }

    private function createService(): SegmentDetailQueryService
    {
        return new SegmentDetailQueryService($this->createMock(CoreSegmentEditorGatewayInterface::class));
    }
}
