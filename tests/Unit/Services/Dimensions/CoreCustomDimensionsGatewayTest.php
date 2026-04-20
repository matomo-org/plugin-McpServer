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
use Piwik\Plugins\CustomDimensions\API as CustomDimensionsApi;
use Piwik\Plugins\McpServer\Services\Dimensions\CoreCustomDimensionsGateway;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;

/**
 * @group McpServer
 * @group Plugins
 */
class CoreCustomDimensionsGatewayTest extends TestCase
{
    protected function tearDown(): void
    {
        CustomDimensionsApi::unsetInstance();
        parent::tearDown();
    }

    public function testGetConfiguredCustomDimensionsReturnsTypedList(): void
    {
        $api = $this->createMock(CustomDimensionsApi::class);
        $api->expects(self::once())
            ->method('getConfiguredCustomDimensions')
            ->with(7)
            ->willReturn([
                ['idcustomdimension' => '3', 'name' => 'Dimension Alpha'],
                ['idcustomdimension' => '4', 'name' => 'Dimension Beta'],
            ]);
        CustomDimensionsApi::setSingletonInstance($api);

        $gateway = new CoreCustomDimensionsGateway();
        $result = $gateway->getConfiguredCustomDimensions(7);

        self::assertCount(2, $result);
        self::assertSame('Dimension Alpha', $result[0]['name'] ?? null);
        self::assertSame('Dimension Beta', $result[1]['name'] ?? null);
    }

    public function testGetConfiguredCustomDimensionsRejectsInvalidTopLevelPayload(): void
    {
        $api = $this->createMock(CustomDimensionsApi::class);
        $api->expects(self::once())
            ->method('getConfiguredCustomDimensions')
            ->willReturn(['unexpected' => 'shape']);
        CustomDimensionsApi::setSingletonInstance($api);

        $gateway = new CoreCustomDimensionsGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Custom dimensions data is invalid.');
        $gateway->getConfiguredCustomDimensions(7);
    }

    public function testGetConfiguredCustomDimensionsRejectsInvalidRowPayload(): void
    {
        $api = $this->createMock(CustomDimensionsApi::class);
        $api->expects(self::once())
            ->method('getConfiguredCustomDimensions')
            ->willReturn([
                ['idcustomdimension' => '3', 'name' => 'Dimension Alpha'],
                ['invalid-row'],
            ]);
        CustomDimensionsApi::setSingletonInstance($api);

        $gateway = new CoreCustomDimensionsGateway();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Custom dimensions data is invalid.');
        $gateway->getConfiguredCustomDimensions(7);
    }

    public function testGetConfiguredCustomDimensionsMapsMessageBasedAccessFailure(): void
    {
        $api = $this->createMock(CustomDimensionsApi::class);
        $api->expects(self::once())
            ->method('getConfiguredCustomDimensions')
            ->willThrowException(new \RuntimeException('CheckUserHasViewAccess failed'));
        CustomDimensionsApi::setSingletonInstance($api);

        $gateway = new CoreCustomDimensionsGateway();

        $this->expectException(AccessDeniedLikeException::class);
        $this->expectExceptionMessage('No access to this resource.');
        $gateway->getConfiguredCustomDimensions(7);
    }
}
