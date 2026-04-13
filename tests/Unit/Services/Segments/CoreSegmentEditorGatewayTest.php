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

/**
 * @group McpServer
 * @group Plugins
 */
class CoreSegmentEditorGatewayTest extends TestCase
{
    public function testGetAllReturnsTypedList(): void
    {
        $gateway = new CoreSegmentEditorGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                self::assertSame('SegmentEditor.getAll', $method);
                self::assertSame(['idSite' => 9], $paramOverride);
                self::assertSame([], $defaultRequest);

                return [
                    ['idsegment' => '1', 'name' => 'Segment Alpha'],
                    ['idsegment' => '2', 'name' => 'Segment Beta'],
                ];
            },
        );
        $result = $gateway->getAll(9);

        self::assertCount(2, $result);
        self::assertSame('Segment Alpha', $result[0]['name'] ?? null);
        self::assertSame('Segment Beta', $result[1]['name'] ?? null);
    }

    public function testGetAllRejectsInvalidTopLevelPayload(): void
    {
        $gateway = new CoreSegmentEditorGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return ['unexpected' => 'shape'];
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Segment data is invalid.');
        $gateway->getAll(9);
    }

    public function testGetAllRejectsInvalidRowPayload(): void
    {
        $gateway = new CoreSegmentEditorGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return [
                    ['idsegment' => '1', 'name' => 'Segment Alpha'],
                    ['invalid-row'],
                ];
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Segment data is invalid.');
        $gateway->getAll(9);
    }
}
