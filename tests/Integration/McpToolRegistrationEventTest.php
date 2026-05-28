<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Piwik\Piwik;
use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\StatefulContributedMcpTool;
use Piwik\Plugins\McpServer\tests\Framework\StubMcpTool;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * End-to-end coverage for the McpServer.addTools / McpServer.filterTools events:
 * a tool contributed through the events must actually surface in (or be hidden
 * from) a real listTools response built by the production McpServerFactory and
 * McpToolsProvider, not just by the provider in isolation.
 *
 * @group McpServer
 * @group Plugins
 */
class McpToolRegistrationEventTest extends IntegrationTestCase
{
    private const CONTRIBUTED_TOOL_NAME = 'matomo_test_contributed_tool';

    public function testAddToolsEventSurfacesContributedToolInListTools(): void
    {
        Piwik::addAction('McpServer.addTools', function (array &$tools): void {
            $tools[] = new StubMcpTool(self::CONTRIBUTED_TOOL_NAME);
        });

        $toolNames = $this->listToolNames();

        self::assertContains(self::CONTRIBUTED_TOOL_NAME, $toolNames);
        // The contributed tool is added alongside the built-in tools, not in place of them.
        self::assertContains(SiteList::TOOL_NAME, $toolNames);
    }

    public function testFilterToolsEventHidesContributedTool(): void
    {
        Piwik::addAction('McpServer.addTools', function (array &$tools): void {
            $tools[] = new StubMcpTool(self::CONTRIBUTED_TOOL_NAME);
        });
        Piwik::addAction('McpServer.filterTools', static function (array &$tools): void {
            $tools = array_values(array_filter(
                $tools,
                static fn(McpTool $tool): bool => $tool->getName() !== self::CONTRIBUTED_TOOL_NAME,
            ));
        });

        $toolNames = $this->listToolNames();

        self::assertNotContains(self::CONTRIBUTED_TOOL_NAME, $toolNames);
        // Filtering out one tool must leave the built-in set intact.
        self::assertContains(SiteList::TOOL_NAME, $toolNames);
    }

    public function testAddToolsEventWithBadEntryIsSkippedAndListToolsStillSucceeds(): void
    {
        // A plugin contributing a non-McpTool entry must not take the whole
        // MCP endpoint down: the bad entry is dropped and the built-in tools
        // still list successfully.
        Piwik::addAction('McpServer.addTools', function (array &$tools): void {
            $tools[] = new \stdClass();
            $tools[] = new StubMcpTool(self::CONTRIBUTED_TOOL_NAME);
        });

        $toolNames = $this->listToolNames();

        self::assertContains(SiteList::TOOL_NAME, $toolNames);
        // The valid contribution alongside the bad entry is still registered.
        self::assertContains(self::CONTRIBUTED_TOOL_NAME, $toolNames);
    }

    public function testCallToolUsesTheContributedToolInstanceState(): void
    {
        $expectedState = 'state-from-contributed-object';

        Piwik::addAction('McpServer.addTools', static function (array &$tools) use ($expectedState): void {
            $tools[] = new StatefulContributedMcpTool($expectedState);
        });

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            StatefulContributedMcpTool::TOOL_NAME,
            ['value' => 'payload-value'],
            'call-stateful-contributed-tool',
        );

        self::assertSame($expectedState, $content['state'] ?? null);
        self::assertSame('payload-value', $content['value'] ?? null);
    }

    /**
     * @return list<string>
     */
    private function listToolNames(): array
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-tools-1');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        return array_values(array_map(static fn($tool): string => $tool->name, $result->tools));
    }
}
