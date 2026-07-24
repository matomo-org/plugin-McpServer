<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use PHPUnit\Framework\TestCase;
use Piwik\EventDispatcher;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\Manager as PluginManager;
use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\McpToolsProvider;
use Psr\Container\ContainerInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class McpToolsProviderTest extends TestCase
{
    public function testReturnsBuiltinToolInstancesResolvedFromContainer(): void
    {
        $builtins = $this->makeBuiltinStubs();

        $provider = new McpToolsProvider(
            $this->makeContainer($builtins),
            $this->newEventDispatcher(),
            $this->newLogger(),
        );

        $tools = $provider->getAllTools();

        self::assertCount(count($builtins), $tools);
        self::assertSame(array_values($builtins), $tools);
    }

    public function testAddToolsEventCanAppendPluginContributedTools(): void
    {
        $contributed = $this->createMock(McpTool::class);

        $eventDispatcher = $this->newEventDispatcher();
        $eventDispatcher->addObserver('McpServer.addTools', function (array &$tools) use ($contributed): void {
            $tools[] = $contributed;
        });

        $provider = new McpToolsProvider($this->makeContainerWithStubs(), $eventDispatcher, $this->newLogger());

        $tools = $provider->getAllTools();

        self::assertContains($contributed, $tools);
    }

    public function testFilterToolsEventCanRemoveTools(): void
    {
        $eventDispatcher = $this->newEventDispatcher();
        $eventDispatcher->addObserver('McpServer.filterTools', static function (array &$tools): void {
            $tools = [];
        });

        $provider = new McpToolsProvider($this->makeContainerWithStubs(), $eventDispatcher, $this->newLogger());

        $tools = $provider->getAllTools();

        self::assertSame([], $tools);
    }

    public function testFilterToolsRunsAfterAddTools(): void
    {
        $extra = $this->createMock(McpTool::class);

        $eventDispatcher = $this->newEventDispatcher();
        $eventDispatcher->addObserver('McpServer.addTools', function (array &$tools) use ($extra): void {
            $tools[] = $extra;
        });

        $eventDispatcher->addObserver('McpServer.filterTools', static function (array &$tools) use ($extra): void {
            $tools = array_values(array_filter(
                $tools,
                static fn(mixed $tool): bool => $tool === $extra,
            ));
        });

        $provider = new McpToolsProvider($this->makeContainerWithStubs(), $eventDispatcher, $this->newLogger());

        $tools = $provider->getAllTools();

        self::assertSame([$extra], $tools);
    }

    public function testSkipsNonMcpToolEntryContributedThroughEventAndLogsWarning(): void
    {
        $builtins = $this->makeBuiltinStubs();

        $eventDispatcher = $this->newEventDispatcher();
        $eventDispatcher->addObserver('McpServer.addTools', static function (array &$tools): void {
            $tools[] = new \stdClass();
        });
        $eventDispatcher->addObserver('McpServer.filterTools', static function (array &$tools): void {
            foreach ($tools as $tool) {
                self::assertInstanceOf(McpTool::class, $tool);
            }
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('non-McpTool'), self::arrayHasKey('type'));

        $provider = new McpToolsProvider($this->makeContainer($builtins), $eventDispatcher, $logger);

        $tools = $provider->getAllTools();

        // The bad entry is dropped; the built-in tools continue to be served
        // in order, so one misbehaving plugin cannot break the whole server.
        self::assertSame(array_values($builtins), $tools);
    }

    public function testFallsBackToBuiltinsWhenFilterToolsReplacesListWithNonArrayAndLogsError(): void
    {
        $builtins = $this->makeBuiltinStubs();

        $eventDispatcher = $this->newEventDispatcher();
        $eventDispatcher->addObserver('McpServer.filterTools', static function (&$tools): void {
            $tools = 'not-an-array';
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('non-array'), self::arrayHasKey('type'));

        $provider = new McpToolsProvider($this->makeContainer($builtins), $eventDispatcher, $logger);

        $tools = $provider->getAllTools();

        self::assertSame(array_values($builtins), $tools);
    }

    public function testFallsBackToBuiltinsBeforeFilterWhenAddToolsReplacesListWithNonArrayAndLogsError(): void
    {
        $builtins = $this->makeBuiltinStubs();

        $eventDispatcher = $this->newEventDispatcher();
        $eventDispatcher->addObserver('McpServer.addTools', static function (&$tools): void {
            $tools = 'not-an-array';
        });
        $eventDispatcher->addObserver('McpServer.filterTools', static function (array &$tools) use ($builtins): void {
            self::assertSame(array_values($builtins), $tools);
            $tools = [];
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('non-array'), self::arrayHasKey('type'));

        $provider = new McpToolsProvider($this->makeContainer($builtins), $eventDispatcher, $logger);

        $tools = $provider->getAllTools();

        self::assertSame([], $tools);
    }

    public function testSkipsDuplicateToolNamesAndLogsWarning(): void
    {
        $builtins = $this->makeBuiltinStubs();
        $builtinToolName = array_values($builtins)[0]->getName();

        $contributed = $this->createConfiguredMock(McpTool::class, [
            'getName' => $builtinToolName,
        ]);

        $eventDispatcher = $this->newEventDispatcher();
        $eventDispatcher->addObserver('McpServer.addTools', function (array &$tools) use ($contributed): void {
            $tools[] = $contributed;
        });

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(self::stringContains('tool names must be unique'), self::arrayHasKey('name'));

        $provider = new McpToolsProvider($this->makeContainer($builtins), $eventDispatcher, $logger);

        $tools = $provider->getAllTools();

        self::assertSame(array_values($builtins), $tools);
    }

    public function testThrowsWhenOwnBuiltinClassDoesNotResolveToMcpTool(): void
    {
        // A built-in that fails to resolve is McpServer's own bug, not a
        // third-party contribution, so it must still fail loudly.
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn(new \stdClass());

        $provider = new McpToolsProvider($container, $this->newEventDispatcher(), $this->newLogger());

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('did not resolve to an McpTool instance');

        $provider->getAllTools();
    }

    /**
     * A dispatcher backed by a Plugin\Manager that reports no plugins runs
     * entirely in-memory: only the observers a test registers itself fire, so
     * no Matomo environment is needed.
     */
    private function newEventDispatcher(): EventDispatcher
    {
        $pluginManager = $this->createMock(PluginManager::class);
        $pluginManager->method('getPluginsLoadedAndActivated')->willReturn([]);

        return new EventDispatcher($pluginManager);
    }

    private function newLogger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }

    private function makeContainerWithStubs(): ContainerInterface
    {
        return $this->makeContainer($this->makeBuiltinStubs());
    }

    /**
     * Stub instances keyed by built-in tool class-string, one per class the
     * provider resolves from the container.
     *
     * @return array<class-string, McpTool>
     */
    private function makeBuiltinStubs(): array
    {
        $tools = [];
        foreach (McpToolsProvider::BUILTIN_TOOL_CLASSES as $toolClass) {
            self::assertTrue(defined("{$toolClass}::TOOL_NAME"));

            $tools[$toolClass] = $this->createConfiguredMock(McpTool::class, [
                'getName' => constant("{$toolClass}::TOOL_NAME"),
            ]);
        }

        return $tools;
    }

    /**
     * @param array<class-string, McpTool> $tools
     */
    private function makeContainer(array $tools): ContainerInterface
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnCallback(static function (string $id) use ($tools): McpTool {
            if (!array_key_exists($id, $tools)) {
                throw new \LogicException("Unexpected container lookup for {$id}");
            }

            return $tools[$id];
        });

        return $container;
    }
}
