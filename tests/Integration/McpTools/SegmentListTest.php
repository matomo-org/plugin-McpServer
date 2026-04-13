<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Piwik\Plugins\McpServer\McpTools\SegmentList;
use Piwik\Plugins\McpServer\Support\Pagination\SegmentsPagination;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SegmentListTest extends IntegrationTestCase
{
    private int $idSite = 0;
    private int $idSiteOther = 0;
    private string $segmentNameAlpha = '';
    private string $segmentNameBeta = '';
    private string $segmentNameGamma = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Segment Test Site',
            'https://segments.test',
        );
        $this->idSiteOther = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Segment Other Test Site',
            'https://segments-other.test',
        );

        $suffix = substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8);
        $this->segmentNameAlpha = 'MCP Segment Alpha ' . $suffix;
        $this->segmentNameBeta = 'MCP Segment Beta ' . $suffix;
        $this->segmentNameGamma = 'MCP Segment Gamma ' . $suffix;

        SegmentEditorApi::getInstance()->add($this->segmentNameAlpha, 'countryCode==de', $this->idSite);
        SegmentEditorApi::getInstance()->add($this->segmentNameBeta, 'countryCode==fr', $this->idSite);
        SegmentEditorApi::getInstance()->add($this->segmentNameGamma, 'countryCode==ch', $this->idSite);
    }

    public function testReturnsPagedResults(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2],
            __METHOD__ . '#1',
        );

        self::assertIsArray($firstPage['segments'] ?? null);
        self::assertCount(2, $firstPage['segments']);
        self::assertTrue($firstPage['has_more']);
        self::assertIsString($firstPage['next_cursor']);
        self::assertSame(3, $firstPage['total_rows'] ?? null);

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'cursor' => $firstPage['next_cursor']],
            __METHOD__ . '#2',
        );
        self::assertIsArray($secondPage['segments'] ?? null);
        self::assertNotEmpty($secondPage['segments']);
        self::assertSame(3, $secondPage['total_rows'] ?? null);
    }

    public function testSupportsSortByIdDesc(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 3, 'sort' => SegmentsPagination::SORT_ID_DESC],
            __METHOD__,
        );
        $segments = $content['segments'] ?? null;
        self::assertIsArray($segments);
        self::assertCount(3, $segments);
        self::assertGreaterThan($segments[1]['idsegment'] ?? 0, $segments[0]['idsegment'] ?? 0);
    }

    public function testIdPaginationIncludesNewRowsAddedAfterFirstPageWhenTheySortAfterCursor(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'sort' => SegmentsPagination::SORT_ID_ASC],
            __METHOD__ . '#1',
        );
        $segments = $firstPage['segments'] ?? null;
        self::assertIsArray($segments);
        self::assertCount(2, $segments);
        self::assertIsString($firstPage['next_cursor'] ?? null);

        SegmentEditorApi::getInstance()->add(
            'MCP Segment Delta ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'countryCode==it',
            $this->idSite,
        );

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'limit' => 2,
                'sort' => SegmentsPagination::SORT_ID_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2',
        );
        $segments = $secondPage['segments'] ?? null;
        self::assertIsArray($segments);
        self::assertCount(2, $segments);
    }

    public function testNamePaginationDoesNotBackfillRowsAddedBeforeCursorBoundary(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'sort' => SegmentsPagination::SORT_NAME_ASC],
            __METHOD__ . '#1',
        );
        $segments = $firstPage['segments'] ?? null;
        self::assertIsArray($segments);
        self::assertCount(2, $segments);
        self::assertIsString($firstPage['next_cursor'] ?? null);

        SegmentEditorApi::getInstance()->add(
            'MCP Segment Aaron ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'countryCode==at',
            $this->idSite,
        );

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'limit' => 2,
                'sort' => SegmentsPagination::SORT_NAME_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2',
        );
        $segments = $secondPage['segments'] ?? null;
        self::assertIsArray($segments);
        self::assertCount(1, $segments);
    }

    public function testRejectsInvalidLimit(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 0],
            __METHOD__,
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SegmentList::TOOL_NAME . "':",
            $message->message ?? '',
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
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'sort' => 'invalid'],
            __METHOD__,
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SegmentList::TOOL_NAME . "':",
            $message->message ?? '',
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
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => 'invalid'],
            'Invalid cursor.',
            __METHOD__,
        );
    }

    public function testRejectsCursorSortMismatch(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 1, 'sort' => SegmentsPagination::SORT_ID_DESC],
            __METHOD__ . '#1',
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => $nextCursor, 'sort' => SegmentsPagination::SORT_NAME_ASC],
            'Invalid cursor.',
            __METHOD__ . '#2',
        );
    }

    public function testRejectsCursorFromDifferentSiteContext(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 1, 'sort' => SegmentsPagination::SORT_ID_ASC],
            __METHOD__ . '#1',
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            SegmentList::TOOL_NAME,
            [
                'idSite' => $this->idSiteOther,
                'cursor' => $nextCursor,
                'sort' => SegmentsPagination::SORT_ID_ASC,
            ],
            'Invalid cursor.',
            __METHOD__ . '#2',
        );
    }

    public function testReturnsEmptyResultForUserWithoutViewAccess(): void
    {
        McpAuthTestHelper::asNoAccessUser(function (): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            $content = McpTestHelper::callToolAndAssertSuccess(
                $server,
                $sessionId,
                SegmentList::TOOL_NAME,
                ['idSite' => $this->idSite],
                __METHOD__,
            );
            self::assertSame([], $content['segments'] ?? null);
            self::assertSame(false, $content['has_more'] ?? null);
            self::assertSame(null, $content['next_cursor'] ?? null);
            self::assertSame(0, $content['total_rows'] ?? null);
        });
    }
}
