<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\Support\Pagination\SitesPagination;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SiteListTest extends IntegrationTestCase
{
    private int $idSiteAlpha = 0;
    private int $idSiteBeta = 0;
    private int $idSiteGamma = 0;

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
    }

    public function testReturnsPagedResults(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 2],
            __METHOD__ . '#1'
        );
        self::assertIsArray($firstPage['sites'] ?? null);
        self::assertCount(2, $firstPage['sites']);
        self::assertTrue($firstPage['has_more']);
        self::assertNotEmpty($firstPage['next_cursor']);
        self::assertSame('MCP Test Site Alpha', $firstPage['sites'][0]['name'] ?? null);
        self::assertSame('MCP Test Site Beta', $firstPage['sites'][1]['name'] ?? null);

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 2, 'cursor' => $firstPage['next_cursor']],
            __METHOD__ . '#2'
        );
        self::assertIsArray($secondPage['sites'] ?? null);
        self::assertCount(1, $secondPage['sites']);
        self::assertFalse($secondPage['has_more']);
        self::assertNull($secondPage['next_cursor']);
        self::assertSame('MCP Test Site Gamma', $secondPage['sites'][0]['name'] ?? null);
    }

    public function testSupportsSortByIdDesc(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 3, 'sort' => SitesPagination::SORT_ID_DESC],
            __METHOD__
        );
        $sites = $content['sites'] ?? null;
        self::assertIsArray($sites);
        self::assertSame($this->idSiteGamma, $sites[0]['idsite'] ?? null);
        self::assertSame($this->idSiteBeta, $sites[1]['idsite'] ?? null);
        self::assertSame($this->idSiteAlpha, $sites[2]['idsite'] ?? null);
    }

    public function testIdPaginationIncludesNewRowsAddedAfterFirstPageWhenTheySortAfterCursor(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 2, 'sort' => SitesPagination::SORT_ID_ASC],
            __METHOD__ . '#1'
        );
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

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 2, 'sort' => SitesPagination::SORT_ID_ASC, 'cursor' => $firstPage['next_cursor']],
            __METHOD__ . '#2'
        );
        $sites = $secondPage['sites'] ?? null;
        self::assertIsArray($sites);
        self::assertSame('MCP Test Site Gamma', $sites[0]['name'] ?? null);
        self::assertSame('MCP Test Site Delta', $sites[1]['name'] ?? null);
    }

    public function testNamePaginationDoesNotBackfillRowsAddedBeforeCursorBoundary(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 2, 'sort' => SitesPagination::SORT_NAME_ASC],
            __METHOD__ . '#1'
        );
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

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 2, 'sort' => SitesPagination::SORT_NAME_ASC, 'cursor' => $firstPage['next_cursor']],
            __METHOD__ . '#2'
        );
        $sites = $secondPage['sites'] ?? null;
        self::assertIsArray($sites);

        self::assertCount(1, $sites);
        self::assertSame('MCP Test Site Gamma', $sites[0]['name'] ?? null);
    }

    public function testRejectsInvalidLimit(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 0],
            __METHOD__
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SiteList::TOOL_NAME . "':",
            $message->message ?? ''
        );
        self::assertStringContainsString('limit', $message->message ?? '');
    }

    public function testRejectsInvalidSort(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['sort' => 'invalid'],
            __METHOD__
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SiteList::TOOL_NAME . "':",
            $message->message ?? ''
        );
        self::assertStringContainsString('sort', $message->message ?? '');
    }

    public function testRejectsInvalidCursor(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['cursor' => 'invalid'],
            'Invalid cursor.',
            __METHOD__
        );
    }

    public function testRejectsCursorSortMismatch(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['limit' => 1, 'sort' => SitesPagination::SORT_ID_DESC],
            __METHOD__ . '#1'
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SiteList::TOOL_NAME,
            ['cursor' => $nextCursor, 'sort' => SitesPagination::SORT_NAME_ASC],
            'Invalid cursor.',
            __METHOD__ . '#2'
        );
    }

    public function testReturnsEmptyListForUserWithoutViewAccess(): void
    {
        McpAuthTestHelper::asNoAccessUser(function (): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            $content = McpTestHelper::callToolAndAssertSuccess(
                $server,
                $sessionId,
                SiteList::TOOL_NAME,
                [],
                __METHOD__
            );
            self::assertSame([], $content['sites'] ?? null);
            self::assertFalse($content['has_more'] ?? true);
            self::assertArrayHasKey('next_cursor', $content);
            self::assertNull($content['next_cursor']);
        });
    }
}
