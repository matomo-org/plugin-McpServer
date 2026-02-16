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
use Piwik\Plugins\McpServer\Services\Segments\CoreSegmentEditorGateway;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;

/**
 * @group McpServer
 * @group Plugins
 */
class CoreSegmentEditorGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        SegmentEditorApi::unsetInstance();
        parent::tearDown();
    }

    public function testGetAllReturnsTypedList(): void
    {
        $api = $this->createMock(SegmentEditorApi::class);
        $api->expects(self::once())
            ->method('getAll')
            ->with(9)
            ->willReturn([
                ['idsegment' => '1', 'name' => 'Segment Alpha'],
                ['idsegment' => '2', 'name' => 'Segment Beta'],
            ]);
        SegmentEditorApi::setSingletonInstance($api);

        $gateway = new CoreSegmentEditorGateway();
        $result = $gateway->getAll(9);

        self::assertCount(2, $result);
        self::assertSame('Segment Alpha', $result[0]['name'] ?? null);
        self::assertSame('Segment Beta', $result[1]['name'] ?? null);
    }

    public function testGetAllRejectsInvalidTopLevelPayload(): void
    {
        $api = $this->createMock(SegmentEditorApi::class);
        $api->expects(self::once())
            ->method('getAll')
            ->willReturn(['unexpected' => 'shape']);
        SegmentEditorApi::setSingletonInstance($api);

        $gateway = new CoreSegmentEditorGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Segment data is invalid.');
        $gateway->getAll(9);
    }

    public function testGetAllRejectsInvalidRowPayload(): void
    {
        $api = $this->createMock(SegmentEditorApi::class);
        $api->expects(self::once())
            ->method('getAll')
            ->willReturn([
                ['idsegment' => '1', 'name' => 'Segment Alpha'],
                ['invalid-row'],
            ]);
        SegmentEditorApi::setSingletonInstance($api);

        $gateway = new CoreSegmentEditorGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Segment data is invalid.');
        $gateway->getAll(9);
    }
}
