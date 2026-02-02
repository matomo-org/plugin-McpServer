<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Piwik\Plugins\McpServer\McpTools\SiteSearch;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SiteSearchTest extends IntegrationTestCase
{
    private int $idSiteAlpha;
    private int $idSiteBeta;
    private int $idSiteGamma;

    public function setUp(): void
    {
        parent::setUp();

        $this->idSiteAlpha = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Test Site Alpha',
            'https://alpha.test'
        );

        $this->idSiteBeta = Fixture::createWebsite(
            '2010-01-02 00:00:00',
            0,
            'MCP Test Site Beta',
            'https://beta.test'
        );

        $this->idSiteGamma = Fixture::createWebsite(
            '2010-01-03 00:00:00',
            0,
            'MCP Test Site Gamma',
            'https://gamma.test'
        );

        SitesManagerApi::getInstance()->addSiteAliasUrls($this->idSiteAlpha, ['https://alias.test']);
    }

    public function testRejectsEmptySearch(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => '   '],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertTrue($result->isError);
        self::assertSame("Parameter 'search' missing or invalid.", $result->content[0]->text ?? null);
    }

    public function testReturnsPagedResults(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'Test Site', 'limit' => 2],
            __METHOD__ . '#1'
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);

        $firstPage = $result->structuredContent;
        self::assertIsArray($firstPage['sites'] ?? null);
        self::assertCount(2, $firstPage['sites']);
        self::assertTrue($firstPage['has_more']);
        self::assertNotEmpty($firstPage['next_cursor']);
        self::assertSame('MCP Test Site Alpha', $firstPage['sites'][0]['name'] ?? null);
        self::assertSame('MCP Test Site Beta', $firstPage['sites'][1]['name'] ?? null);

        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'Test Site', 'limit' => 2, 'cursor' => $firstPage['next_cursor']],
            __METHOD__ . '#2'
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);

        $secondPage = $result->structuredContent;
        self::assertIsArray($secondPage['sites'] ?? null);
        self::assertCount(1, $secondPage['sites']);
        self::assertFalse($secondPage['has_more']);
        self::assertNull($secondPage['next_cursor']);
        self::assertSame('MCP Test Site Gamma', $secondPage['sites'][0]['name'] ?? null);
    }

    public function testMatchesMainUrl(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'alpha.test', 'limit' => 10],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        $sites = $result->structuredContent['sites'] ?? null;
        self::assertIsArray($sites);
        $ids = array_map(static fn(array $site) => $site['idsite'] ?? null, $sites);
        self::assertContains($this->idSiteAlpha, $ids);
    }

    public function testDoesNotMatchAliasUrl(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'alias.test', 'limit' => 10],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        $sites = $result->structuredContent['sites'] ?? null;
        self::assertIsArray($sites);
        $ids = array_map(static fn(array $site) => $site['idsite'] ?? null, $sites);
        self::assertNotContains($this->idSiteAlpha, $ids);
    }

    public function testSupportsSortByIdDesc(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'Test Site', 'limit' => 3, 'sort' => SiteSearch::SORT_ID_DESC],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        $sites = $result->structuredContent['sites'] ?? null;
        self::assertIsArray($sites);
        self::assertSame($this->idSiteGamma, $sites[0]['idsite'] ?? null);
        self::assertSame($this->idSiteBeta, $sites[1]['idsite'] ?? null);
        self::assertSame($this->idSiteAlpha, $sites[2]['idsite'] ?? null);
    }

    public function testIdPaginationIncludesNewRowsAddedAfterFirstPageWhenTheySortAfterCursor(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'MCP Test Site', 'limit' => 2, 'sort' => SiteSearch::SORT_ID_ASC],
            __METHOD__ . '#1'
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        $firstPage = $result->structuredContent;
        $sites = $firstPage['sites'] ?? null;
        self::assertIsArray($sites);
        self::assertSame('MCP Test Site Alpha', $sites[0]['name'] ?? null);
        self::assertSame('MCP Test Site Beta', $sites[1]['name'] ?? null);
        self::assertIsString($firstPage['next_cursor'] ?? null);

        Fixture::createWebsite(
            '2010-01-04 00:00:00',
            0,
            'MCP Test Site Delta',
            'https://delta.test'
        );

        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            [
                'search' => 'MCP Test Site',
                'limit' => 2,
                'sort' => SiteSearch::SORT_ID_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2'
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        $secondPage = $result->structuredContent;
        $sites = $secondPage['sites'] ?? null;
        self::assertIsArray($sites);
        self::assertSame('MCP Test Site Gamma', $sites[0]['name'] ?? null);
        self::assertSame('MCP Test Site Delta', $sites[1]['name'] ?? null);
    }

    public function testNamePaginationDoesNotBackfillRowsAddedBeforeCursorBoundary(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'MCP Test Site', 'limit' => 2, 'sort' => SiteSearch::SORT_NAME_ASC],
            __METHOD__ . '#1'
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        $firstPage = $result->structuredContent;
        $sites = $firstPage['sites'] ?? null;
        self::assertIsArray($sites);
        self::assertSame('MCP Test Site Alpha', $sites[0]['name'] ?? null);
        self::assertSame('MCP Test Site Beta', $sites[1]['name'] ?? null);
        self::assertIsString($firstPage['next_cursor'] ?? null);

        Fixture::createWebsite(
            '2010-01-04 00:00:00',
            0,
            'MCP Test Site Aaron',
            'https://aaron.test'
        );

        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            [
                'search' => 'MCP Test Site',
                'limit' => 2,
                'sort' => SiteSearch::SORT_NAME_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2'
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        $secondPage = $result->structuredContent;
        $sites = $secondPage['sites'] ?? null;
        self::assertIsArray($sites);

        self::assertCount(1, $sites);
        self::assertSame('MCP Test Site Gamma', $sites[0]['name'] ?? null);
    }

    public function testRejectsInvalidLimit(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'test', 'limit' => 0],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SiteSearch::TOOL_NAME . "':",
            $message->message ?? ''
        );
        self::assertStringContainsString('limit', $message->message ?? '');
    }

    public function testRejectsInvalidSort(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'test', 'sort' => 'invalid'],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SiteSearch::TOOL_NAME . "':",
            $message->message ?? ''
        );
        self::assertStringContainsString('sort', $message->message ?? '');
    }

    public function testRejectsInvalidCursor(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'Test Site', 'cursor' => 'invalid'],
            __METHOD__
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertTrue($result->isError);
        self::assertSame('Invalid cursor.', $result->content[0]->text ?? null);
    }

    public function testRejectsCursorSortMismatch(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'Test Site', 'limit' => 1, 'sort' => SiteSearch::SORT_ID_DESC],
            __METHOD__ . '#1'
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertFalse($result->isError);
        self::assertIsArray($result->structuredContent);
        $nextCursor = $result->structuredContent['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        $payload = McpTestHelper::makeCallToolRequest(
            SiteSearch::TOOL_NAME,
            ['search' => 'Test Site', 'cursor' => $nextCursor, 'sort' => SiteSearch::SORT_NAME_ASC],
            __METHOD__ . '#2'
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        self::assertTrue($result->isError);
        self::assertSame('Invalid cursor.', $result->content[0]->text ?? null);
    }

    public function testReturnsEmptyListForUserWithoutViewAccess(): void
    {
        McpAuthTestHelper::asNoAccessUser(function (): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            $payload = McpTestHelper::makeCallToolRequest(
                SiteSearch::TOOL_NAME,
                ['search' => 'test'],
                __METHOD__
            );

            $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
            $message = McpTestHelper::decodeResponse($response);
            $result = McpTestHelper::parseCallTool($message);

            self::assertFalse($result->isError);
            self::assertIsArray($result->structuredContent);
            self::assertSame([], $result->structuredContent['sites'] ?? null);
            self::assertFalse($result->structuredContent['has_more'] ?? true);
            self::assertArrayHasKey('next_cursor', $result->structuredContent);
            self::assertNull($result->structuredContent['next_cursor']);
        });
    }
}
