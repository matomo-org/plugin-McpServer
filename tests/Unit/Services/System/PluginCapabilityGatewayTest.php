<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\System;

use PHPUnit\Framework\TestCase;
use Piwik\Container\StaticContainer;
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\Services\System\PluginCapabilityGateway;

/**
 * @group McpServer
 * @group Plugins
 */
class PluginCapabilityGatewayTest extends TestCase
{
    private ?Manager $originalPluginManager = null;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var Manager $manager */
        $manager = StaticContainer::get('Piwik\\Plugin\\Manager');
        $this->originalPluginManager = $manager;
    }

    protected function tearDown(): void
    {
        if ($this->originalPluginManager !== null) {
            StaticContainer::getContainer()->set('Piwik\\Plugin\\Manager', $this->originalPluginManager);
        }

        parent::tearDown();
    }

    public function testIsPluginActivatedReturnsTrueWhenPluginIsActive(): void
    {
        $manager = $this->createMock(Manager::class);
        $manager->expects(self::once())
            ->method('isPluginActivated')
            ->with('Goals')
            ->willReturn(true);

        StaticContainer::getContainer()->set('Piwik\\Plugin\\Manager', $manager);

        $gateway = new PluginCapabilityGateway();

        self::assertTrue($gateway->isPluginActivated('Goals'));
    }

    public function testIsPluginActivatedReturnsFalseWhenPluginIsInactive(): void
    {
        $manager = $this->createMock(Manager::class);
        $manager->expects(self::once())
            ->method('isPluginActivated')
            ->with('SegmentEditor')
            ->willReturn(false);

        StaticContainer::getContainer()->set('Piwik\\Plugin\\Manager', $manager);

        $gateway = new PluginCapabilityGateway();

        self::assertFalse($gateway->isPluginActivated('SegmentEditor'));
    }
}
