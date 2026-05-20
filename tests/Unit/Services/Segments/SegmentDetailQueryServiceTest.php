<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Segments;

use PHPUnit\Framework\TestCase;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\CoreSegmentEditorGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\Services\Segments\SegmentDetailQueryService;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;

/**
 * @group McpServer
 * @group Plugins
 */
class SegmentDetailQueryServiceTest extends TestCase
{
    public function testGetSegmentDetailsForSiteThrowsWhenPluginIsUnavailable(): void
    {
        $gateway = $this->createMock(CoreSegmentEditorGatewayInterface::class);
        $gateway->expects(self::never())->method('getAll');

        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->expects(self::once())
            ->method('isPluginActivated')
            ->with('SegmentEditor')
            ->willReturn(false);

        $service = new SegmentDetailQueryService($gateway, $capabilityGateway);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('SegmentEditor plugin is not available.');
        $service->getSegmentDetailsForSite(9);
    }

    public function testNormalizeSegmentDetailDataThrowsWhenFieldIsMissing(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSegmentDetailData();
        unset($data['login']);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Segment detail item is incomplete (missing 'login').");

        $service->normalizeSegmentDetailData($data, 'Segment detail item');
    }

    public function testNormalizeSegmentDetailDataThrowsWhenFieldIsInvalid(): void
    {
        $service = $this->createService();
        $data = $this->makeValidSegmentDetailData();
        $data['auto_archive'] = 'invalid';

        $this->expectException(McpToolCallException::class);
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

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Segment detail data is invalid.');

        $service->normalizeSegmentDetailRows(
            'invalid',
            'Segment detail data is invalid.',
            'Segment detail item',
        );
    }

    public function testNormalizeSegmentDetailRowsThrowsWhenRowIsNotArray(): void
    {
        $service = $this->createService();

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Segment detail data is invalid.');

        $service->normalizeSegmentDetailRows(
            ['invalid'],
            'Segment detail data is invalid.',
            'Segment detail item',
        );
    }

    /**
     * @dataProvider provideInstanceBasedAccessExceptions
     */
    public function testGetSegmentDetailsForSiteMapsInstanceBasedAccessFailureToNotFound(\Throwable $exception): void
    {
        $gateway = $this->createMock(CoreSegmentEditorGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('getAll')
            ->with(9)
            ->willThrowException($exception);

        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->expects(self::once())
            ->method('isPluginActivated')
            ->with('SegmentEditor')
            ->willReturn(true);

        $service = new SegmentDetailQueryService($gateway, $capabilityGateway);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Segment not found.');
        $service->getSegmentDetailsForSite(9);
    }

    /**
     * @return array<string, array{0: \Throwable}>
     */
    public static function provideInstanceBasedAccessExceptions(): array
    {
        return [
            'NoAccessException with empty message' => [new NoAccessException('')],
            'AccessDeniedLikeException with empty message' => [new AccessDeniedLikeException('')],
        ];
    }

    public function testGetSegmentDetailsForSiteMapsMessageBasedAccessFailureToNotFound(): void
    {
        $gateway = $this->createMock(CoreSegmentEditorGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('getAll')
            ->with(9)
            ->willThrowException(new \RuntimeException('CheckUserHasViewAccess failed'));

        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->expects(self::once())
            ->method('isPluginActivated')
            ->with('SegmentEditor')
            ->willReturn(true);

        $service = new SegmentDetailQueryService($gateway, $capabilityGateway);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Segment not found.');
        $service->getSegmentDetailsForSite(9);
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
        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->method('isPluginActivated')->willReturn(true);

        return new SegmentDetailQueryService(
            $this->createMock(CoreSegmentEditorGatewayInterface::class),
            $capabilityGateway,
        );
    }
}
