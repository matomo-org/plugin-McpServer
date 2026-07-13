<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Piwik\EventDispatcher;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\McpTools\ApiCallCreate;
use Piwik\Plugins\McpServer\McpTools\ApiCallDelete;
use Piwik\Plugins\McpServer\McpTools\ApiCallFull;
use Piwik\Plugins\McpServer\McpTools\ApiCallRead;
use Piwik\Plugins\McpServer\McpTools\ApiCallUpdate;
use Piwik\Plugins\McpServer\McpTools\ApiGet;
use Piwik\Plugins\McpServer\McpTools\ApiList;
use Piwik\Plugins\McpServer\McpTools\DimensionGet;
use Piwik\Plugins\McpServer\McpTools\DimensionList;
use Piwik\Plugins\McpServer\McpTools\GoalGet;
use Piwik\Plugins\McpServer\McpTools\GoalList;
use Piwik\Plugins\McpServer\McpTools\ReportList;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\McpTools\SegmentGet;
use Piwik\Plugins\McpServer\McpTools\SegmentList;
use Piwik\Plugins\McpServer\McpTools\SiteGet;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\McpTools\SiteSearch;
use Psr\Container\ContainerInterface;

/**
 * Resolves the set of MCP tools exposed by the server.
 *
 * Built-in tools shipped by the McpServer plugin are listed in
 * {@see McpToolsProvider::BUILTIN_TOOL_CLASSES}. Other plugins extend or
 * restrict the set by listening to the {@hook McpServer.addTools} and
 * {@hook McpServer.filterTools} events.
 */
class McpToolsProvider implements McpToolsProviderInterface
{
    /**
     * Tools shipped by the McpServer plugin. Held as class-strings so the
     * container resolves them at runtime — see resolveBuiltinTools().
     *
     * @var list<class-string<McpTool>>
     */
    public const BUILTIN_TOOL_CLASSES = [
        ApiCallCreate::class,
        ApiCallDelete::class,
        ApiCallFull::class,
        ApiCallRead::class,
        ApiCallUpdate::class,
        ApiGet::class,
        ApiList::class,
        DimensionGet::class,
        DimensionList::class,
        GoalGet::class,
        GoalList::class,
        ReportList::class,
        ReportMetadata::class,
        ReportProcessed::class,
        SegmentGet::class,
        SegmentList::class,
        SiteGet::class,
        SiteList::class,
        SiteSearch::class,
    ];

    public function __construct(
        private ContainerInterface $container,
        private EventDispatcher $eventDispatcher,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Return all MCP tools that should be considered for registration.
     *
     * The list begins with the McpServer built-in tools, lets other plugins
     * append their own via {@hook McpServer.addTools}, then lets plugins
     * restrict the result via {@hook McpServer.filterTools}.
     *
     * Contributions from other plugins are isolated so one misbehaving plugin
     * cannot take the whole MCP server down: a listener that contributes a
     * non-{@see McpTool} entry has that entry skipped (logged as a warning),
     * and a listener that replaces the whole list with a non-array is ignored
     * in favour of the built-in tools (logged as an error). Failing to resolve
     * McpServer's own built-in tools is a plugin misconfiguration and still
     * throws — see resolveBuiltinTools().
     *
     * @return list<McpTool>
     */
    public function getAllTools(): array
    {
        $builtins = $this->resolveBuiltinTools();

        // Other plugins extend and restrict the list through events. Each step
        // drops invalid or duplicate contributions, so one misbehaving plugin
        // cannot take the whole server down and every listener is handed a
        // clean list of McpTool instances.
        $tools = $this->dispatchAddToolsEvent($builtins);

        return $this->dispatchFilterToolsEvent($tools, $builtins);
    }

    /**
     * Turn the raw result a tools event left behind into a clean tool list.
     *
     * Listeners mutate the list by reference and are not constrained by the
     * static type system, hence the `mixed` input. A listener that
     * replaced the whole list with a non-array is ignored in favour of the
     * built-in tools; otherwise entries that are not {@see McpTool} instances
     * or that duplicate an earlier tool name are dropped. Bad contributions are
     * logged and skipped rather than failing the whole server.
     *
     * @param list<McpTool> $builtins Fallback served when $contributed is not an array.
     * @return list<McpTool>
     */
    private function normalizeContribution(mixed $contributed, array $builtins, string $eventName): array
    {
        if (!is_array($contributed)) {
            $this->logger->error(
                'An {event} listener replaced the tool list with a non-array ({type}); '
                . 'ignoring it and falling back to the built-in tools.',
                ['event' => $eventName, 'type' => get_debug_type($contributed)],
            );

            return $builtins;
        }

        $result = [];
        $seenToolNames = [];
        foreach ($contributed as $tool) {
            if (!$tool instanceof McpTool) {
                $this->logger->warning(
                    'An {event} listener contributed a non-McpTool entry ({type}); skipping it.',
                    ['event' => $eventName, 'type' => get_debug_type($tool)],
                );
                continue;
            }

            $toolName = $tool->getName();
            if (isset($seenToolNames[$toolName])) {
                $this->logger->warning(
                    'A duplicate MCP tool name ({name}) was provided; '
                    . 'skipping it because tool names must be unique.',
                    [
                        'name' => $toolName,
                        'firstTool' => $seenToolNames[$toolName],
                        'duplicateTool' => get_debug_type($tool),
                    ],
                );
                continue;
            }

            $seenToolNames[$toolName] = get_debug_type($tool);
            $result[] = $tool;
        }

        return $result;
    }

    /**
     * Pass the built-in tools through the {@hook McpServer.addTools} event,
     * then return the valid, unique McpTool instances listeners left behind.
     *
     * A listener that replaces the whole list with a non-array is ignored in
     * favour of the built-in tools (see {@see getAllTools()}).
     *
     * @param list<McpTool> $builtins
     * @return list<McpTool>
     */
    private function dispatchAddToolsEvent(array $builtins): array
    {
        $tools = $builtins;

        /**
         * Triggered to add custom MCP tools that ship outside the McpServer
         * plugin. Tools added here are subsequently passed through the
         * {@hook McpServer.filterTools} event before being registered.
         *
         * **Example**
         *
         *     public function addMcpTools(&$tools)
         *     {
         *         $tools[] = StaticContainer::get(MyCustomMcpTool::class);
         *     }
         *
         * @param McpTool[] &$tools An array of MCP tool instances.
         */
        $this->eventDispatcher->postEvent('McpServer.addTools', [&$tools]);

        return $this->normalizeContribution($tools, $builtins, 'McpServer.addTools');
    }

    /**
     * Pass the tools through the {@hook McpServer.filterTools} event, then
     * return the valid, unique McpTool instances listeners left behind.
     *
     * A listener that replaces the whole list with a non-array is ignored in
     * favour of the built-in tools (see {@see getAllTools()}).
     *
     * @param list<McpTool> $tools
     * @param list<McpTool> $builtins
     * @return list<McpTool>
     */
    private function dispatchFilterToolsEvent(array $tools, array $builtins): array
    {
        /**
         * Triggered to filter or restrict the set of MCP tools that will be
         * registered with the server. Use this to hide tools conditionally
         * (for example based on plugin state or system settings).
         *
         * **Example**
         *
         *     public function filterMcpTools(&$tools)
         *     {
         *         foreach ($tools as $index => $tool) {
         *             if ($tool->getName() === 'matomo_site_list') {
         *                 unset($tools[$index]);
         *             }
         *         }
         *     }
         *
         * @param McpTool[] &$tools An array of MCP tool instances.
         */
        $this->eventDispatcher->postEvent('McpServer.filterTools', [&$tools]);

        return $this->normalizeContribution($tools, $builtins, 'McpServer.filterTools');
    }

    /**
     * Resolve McpServer's own built-in tools from the container.
     *
     * Unlike third-party contributions, a built-in that fails to resolve is a
     * bug in McpServer itself, so it throws rather than degrading silently.
     *
     * @return list<McpTool>
     */
    private function resolveBuiltinTools(): array
    {
        $tools = [];
        foreach (self::BUILTIN_TOOL_CLASSES as $toolClass) {
            $tool = $this->container->get($toolClass);
            if (!$tool instanceof McpTool) {
                throw new \LogicException(sprintf(
                    '%s did not resolve to an McpTool instance.',
                    $toolClass,
                ));
            }
            $tools[] = $tool;
        }

        return $tools;
    }
}
