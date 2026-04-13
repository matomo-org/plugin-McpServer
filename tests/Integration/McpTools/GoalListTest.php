<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Piwik\Cache;
use Piwik\CacheId;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\McpTools\GoalList;
use Piwik\Plugins\McpServer\Support\Pagination\GoalsPagination;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class GoalListTest extends IntegrationTestCase
{
    private int $idSite = 0;
    private int $idSiteOther = 0;

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Goal Test Site',
            'https://goals.test',
        );
        $this->idSiteOther = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Goal Other Test Site',
            'https://goals-other.test',
        );

        $suffix = substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8);

        GoalsApi::getInstance()->addGoal(
            $this->idSite,
            'MCP Goal Alpha ' . $suffix,
            'event_action',
            'evt-alpha',
            'exact',
        );
        GoalsApi::getInstance()->addGoal(
            $this->idSite,
            'MCP Goal Beta ' . $suffix,
            'event_action',
            'evt-beta',
            'exact',
        );
        GoalsApi::getInstance()->addGoal(
            $this->idSite,
            'MCP Goal Gamma ' . $suffix,
            'event_action',
            'evt-gamma',
            'exact',
        );
    }

    public function testReturnsPagedResults(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2],
            __METHOD__ . '#1',
        );

        self::assertIsArray($firstPage['goals'] ?? null);
        self::assertCount(2, $firstPage['goals']);
        self::assertTrue($firstPage['has_more']);
        self::assertIsString($firstPage['next_cursor']);
        self::assertSame(3, $firstPage['total_rows'] ?? null);

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'cursor' => $firstPage['next_cursor']],
            __METHOD__ . '#2',
        );

        self::assertIsArray($secondPage['goals'] ?? null);
        self::assertNotEmpty($secondPage['goals']);
        self::assertSame(3, $secondPage['total_rows'] ?? null);
    }

    public function testSupportsSortByIdDesc(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 3, 'sort' => GoalsPagination::SORT_ID_DESC],
            __METHOD__,
        );
        $goals = $content['goals'] ?? null;
        self::assertIsArray($goals);
        self::assertCount(3, $goals);
        self::assertGreaterThan($goals[1]['idgoal'] ?? 0, $goals[0]['idgoal'] ?? 0);
    }

    public function testIdPaginationIncludesNewRowsAddedAfterFirstPageWhenTheySortAfterCursor(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'sort' => GoalsPagination::SORT_ID_ASC],
            __METHOD__ . '#1',
        );
        $goals = $firstPage['goals'] ?? null;
        self::assertIsArray($goals);
        self::assertCount(2, $goals);
        self::assertIsString($firstPage['next_cursor'] ?? null);

        GoalsApi::getInstance()->addGoal(
            $this->idSite,
            'MCP Goal Delta ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'event_action',
            'evt-delta',
            'exact',
        );

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'limit' => 2,
                'sort' => GoalsPagination::SORT_ID_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2',
        );
        $goals = $secondPage['goals'] ?? null;
        self::assertIsArray($goals);
        self::assertCount(2, $goals);
    }

    public function testNamePaginationDoesNotBackfillRowsAddedBeforeCursorBoundary(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'sort' => GoalsPagination::SORT_NAME_ASC],
            __METHOD__ . '#1',
        );
        $goals = $firstPage['goals'] ?? null;
        self::assertIsArray($goals);
        self::assertCount(2, $goals);
        self::assertIsString($firstPage['next_cursor'] ?? null);

        GoalsApi::getInstance()->addGoal(
            $this->idSite,
            'MCP Goal Aaron ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'event_action',
            'evt-aaron',
            'exact',
        );

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'limit' => 2,
                'sort' => GoalsPagination::SORT_NAME_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2',
        );
        $goals = $secondPage['goals'] ?? null;
        self::assertIsArray($goals);
        self::assertCount(1, $goals);
    }

    public function testRejectsInvalidLimit(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 0],
            __METHOD__,
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . GoalList::TOOL_NAME . "':",
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
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'sort' => 'invalid'],
            __METHOD__,
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . GoalList::TOOL_NAME . "':",
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
            GoalList::TOOL_NAME,
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
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 1, 'sort' => GoalsPagination::SORT_ID_DESC],
            __METHOD__ . '#1',
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => $nextCursor, 'sort' => GoalsPagination::SORT_NAME_ASC],
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
            GoalList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 1, 'sort' => GoalsPagination::SORT_ID_ASC],
            __METHOD__ . '#1',
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            GoalList::TOOL_NAME,
            [
                'idSite' => $this->idSiteOther,
                'cursor' => $nextCursor,
                'sort' => GoalsPagination::SORT_ID_ASC,
            ],
            'Invalid cursor.',
            __METHOD__ . '#2',
        );
    }

    public function testReturnsEmptyResultForUserWithoutViewAccess(): void
    {
        // Goals API uses request-static transient cache keyed by idSite. In integration
        // tests we run multiple in-process calls while switching auth context, so clear
        // this cache entry before asserting no-access behavior.
        $this->clearGoalsTransientCacheForSite($this->idSite);

        McpAuthTestHelper::asNoAccessUser(function (): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            $content = McpTestHelper::callToolAndAssertSuccess(
                $server,
                $sessionId,
                GoalList::TOOL_NAME,
                ['idSite' => $this->idSite],
                __METHOD__,
            );
            self::assertSame([], $content['goals'] ?? null);
            self::assertSame(false, $content['has_more'] ?? null);
            self::assertSame(null, $content['next_cursor'] ?? null);
            self::assertSame(0, $content['total_rows'] ?? null);
        });
    }

    private function clearGoalsTransientCacheForSite(int $idSite): void
    {
        Cache::getTransientCache()->delete(CacheId::pluginAware('Goals.getGoals.' . $idSite));
    }
}
