<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\Container;

use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ContainerWiringRegressionTest extends IntegrationTestCase
{
    protected static function configureFixture($fixture): void
    {
        parent::configureFixture($fixture);
        $fixture->createSuperUser = true;
    }

    public function testContainerComposedServerSupportsInitializeListToolsAndSiteListCall(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $listToolsPayload = McpTestHelper::makeListToolsRequest('list-tools-1');
        $listToolsResponse = McpTestHelper::postJson($server, $listToolsPayload, ['Mcp-Session-Id' => $sessionId]);
        $listToolsMessage = McpTestHelper::decodeResponse($listToolsResponse);
        $listToolsResult = McpTestHelper::parseListTools($listToolsMessage);

        self::assertSame('list-tools-1', $listToolsMessage->id);
        self::assertNotEmpty(
            $listToolsResult->tools,
            'Expected non-empty tool catalog from container-composed server.'
        );

        $toolNames = array_map(static fn ($tool): string => $tool->name, $listToolsResult->tools);
        self::assertContains(
            SiteList::TOOL_NAME,
            $toolNames,
            sprintf('Expected "%s" in tools list.', SiteList::TOOL_NAME)
        );

        $siteListResult = McpTestHelper::callTool(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 1],
            'site-list-1'
        );
        self::assertFalse($siteListResult->isError);
        self::assertIsArray($siteListResult->structuredContent);

        $content = $siteListResult->structuredContent;
        self::assertIsArray($content['sites'] ?? null);
        self::assertIsBool($content['has_more'] ?? null);
        self::assertArrayHasKey('next_cursor', $content);
    }
}
