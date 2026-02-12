<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Server;
use Piwik\Plugins\API\API as ApiModuleApi;
use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\McpTools\ReportList;
use Piwik\Plugins\McpServer\Support\Pagination\ReportsPagination;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportListTest extends IntegrationTestCase
{
    private int $idSite = 0;
    private int $idSiteOther = 0;

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Report Test Site',
            'https://report.test'
        );
        $this->idSiteOther = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Report Other Test Site',
            'https://report-other.test'
        );
    }

    public function testReturnsPagedResults(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2],
            __METHOD__ . '#1'
        );

        self::assertIsArray($firstPage['reports'] ?? null);
        self::assertCount(2, $firstPage['reports']);
        self::assertTrue($firstPage['has_more']);
        self::assertIsString($firstPage['next_cursor']);

        $first = $firstPage['reports'][0];
        self::assertArrayHasKey('uniqueId', $first);
        self::assertArrayHasKey('module', $first);
        self::assertArrayHasKey('action', $first);
        self::assertArrayHasKey('name', $first);
        self::assertArrayHasKey('category', $first);
        self::assertArrayHasKey('parameters', $first);
        self::assertIsArray($first['parameters']);

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'cursor' => $firstPage['next_cursor']],
            __METHOD__ . '#2'
        );

        self::assertIsArray($secondPage['reports'] ?? null);
        self::assertNotEmpty($secondPage['reports']);
    }

    public function testSupportsSortByNameDesc(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 25, 'sort' => ReportsPagination::SORT_NAME_DESC],
            __METHOD__
        );
        $reports = $content['reports'] ?? null;
        self::assertIsArray($reports);
        self::assertNotEmpty($reports);

        for ($i = 1, $count = count($reports); $i < $count; $i++) {
            self::assertGreaterThanOrEqual(strcmp($reports[$i]['name'], $reports[$i - 1]['name']), 0);
        }
    }

    public function testRejectsInvalidLimit(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 0],
            __METHOD__
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportList::TOOL_NAME . "':",
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
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'sort' => 'invalid'],
            __METHOD__
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . ReportList::TOOL_NAME . "':",
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
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => 'invalid'],
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
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 1, 'sort' => ReportsPagination::SORT_NAME_DESC],
            __METHOD__ . '#1'
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => $nextCursor, 'sort' => ReportsPagination::SORT_NAME_ASC],
            'Invalid cursor.',
            __METHOD__ . '#2'
        );
    }

    public function testRejectsCursorFromDifferentSiteContext(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 1, 'sort' => ReportsPagination::SORT_CATEGORY_ASC],
            __METHOD__ . '#1'
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            [
                'idSite' => $this->idSiteOther,
                'cursor' => $nextCursor,
                'sort' => ReportsPagination::SORT_CATEGORY_ASC,
            ],
            'Invalid cursor.',
            __METHOD__ . '#2'
        );
    }

    public function testOmitsSubtableReports(): void
    {
        $source = ApiModuleApi::getInstance()->getReportMetadata((string) $this->idSite, false, false, true, true);
        $subtableUniqueIds = [];

        foreach ($source as $report) {
            if (!is_array($report)) {
                continue;
            }

            $isSubtable = ($report['isSubtableReport'] ?? null) === true
                || ($report['isSubtableReport'] ?? null) === 1
                || ($report['isSubtableReport'] ?? null) === '1'
                || ($report['isSubtableReports'] ?? null) === true
                || ($report['isSubtableReports'] ?? null) === 1
                || ($report['isSubtableReports'] ?? null) === '1';

            if ($isSubtable && is_string($report['uniqueId'] ?? null)) {
                $subtableUniqueIds[] = $report['uniqueId'];
            }
        }

        self::assertNotEmpty(
            $subtableUniqueIds,
            'Expected fixture metadata to include at least one subtable report.'
        );

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $reports = $this->collectAllReports($server, $sessionId, $this->idSite);
        $toolUniqueIds = [];
        foreach ($reports as $report) {
            $uniqueId = $report['uniqueId'] ?? null;
            self::assertIsString($uniqueId);
            $toolUniqueIds[] = $uniqueId;
        }

        foreach ($subtableUniqueIds as $subtableUniqueId) {
            self::assertNotContains($subtableUniqueId, $toolUniqueIds);
        }
    }

    public function testNamePaginationIncludesNewRowsAddedAfterFirstPageWhenTheySortAfterCursor(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'sort' => ReportsPagination::SORT_NAME_ASC],
            __METHOD__ . '#1'
        );
        $reports = $firstPage['reports'] ?? null;
        self::assertIsArray($reports);
        self::assertCount(2, $reports);
        self::assertIsString($firstPage['next_cursor'] ?? null);
        /** @var array<int, array<string, mixed>> $reports */
        $boundary = $this->extractNameSortBoundaryFromFirstPage(array_values($reports));

        $goalIdLow = (int) GoalsApi::getInstance()->addGoal(
            $this->idSite,
            '0000 MCP Report Low ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'event_action',
            'evt-low',
            'exact'
        );
        $goalIdHigh = (int) GoalsApi::getInstance()->addGoal(
            $this->idSite,
            'zzzz MCP Report Delta ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'event_action',
            'evt-delta',
            'exact'
        );

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'limit' => 500,
                'sort' => ReportsPagination::SORT_NAME_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2'
        );
        $reports = $secondPage['reports'] ?? null;
        self::assertIsArray($reports);
        self::assertNotEmpty($reports);
        /** @var array<int, array<string, mixed>> $reports */
        $resultUniqueIds = $this->extractUniqueIdsFromToolReports(array_values($reports));
        $candidateUniqueIdsAfterBoundary = $this->collectGoalReportUniqueIdsAfterNameBoundary(
            [$goalIdLow, $goalIdHigh],
            $boundary['name'],
            $boundary['uniqueId']
        );
        $candidateUniqueIdsAtOrBeforeBoundary = $this->collectGoalReportUniqueIdsAtOrBeforeNameBoundary(
            [$goalIdLow, $goalIdHigh],
            $boundary['name'],
            $boundary['uniqueId']
        );

        self::assertNotEmpty($candidateUniqueIdsAfterBoundary);
        self::assertNotEmpty($candidateUniqueIdsAtOrBeforeBoundary);
        self::assertTrue($this->containsAnyUniqueId($resultUniqueIds, $candidateUniqueIdsAfterBoundary));
    }

    public function testNamePaginationDoesNotBackfillRowsAddedBeforeCursorBoundary(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'sort' => ReportsPagination::SORT_NAME_ASC],
            __METHOD__ . '#1'
        );
        $reports = $firstPage['reports'] ?? null;
        self::assertIsArray($reports);
        self::assertCount(2, $reports);
        self::assertIsString($firstPage['next_cursor'] ?? null);
        /** @var array<int, array<string, mixed>> $reports */
        $boundary = $this->extractNameSortBoundaryFromFirstPage(array_values($reports));

        $goalIdLow = (int) GoalsApi::getInstance()->addGoal(
            $this->idSite,
            '0000 MCP Report Aaron ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'event_action',
            'evt-aaron',
            'exact'
        );
        $goalIdHigh = (int) GoalsApi::getInstance()->addGoal(
            $this->idSite,
            'zzzz MCP Report High ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'event_action',
            'evt-high',
            'exact'
        );

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            ReportList::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'limit' => 500,
                'sort' => ReportsPagination::SORT_NAME_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2'
        );
        $reports = $secondPage['reports'] ?? null;
        self::assertIsArray($reports);
        self::assertNotEmpty($reports);
        /** @var array<int, array<string, mixed>> $reports */
        $resultUniqueIds = $this->extractUniqueIdsFromToolReports(array_values($reports));
        $candidateUniqueIdsAfterBoundary = $this->collectGoalReportUniqueIdsAfterNameBoundary(
            [$goalIdLow, $goalIdHigh],
            $boundary['name'],
            $boundary['uniqueId']
        );
        $candidateUniqueIdsAtOrBeforeBoundary = $this->collectGoalReportUniqueIdsAtOrBeforeNameBoundary(
            [$goalIdLow, $goalIdHigh],
            $boundary['name'],
            $boundary['uniqueId']
        );

        self::assertNotEmpty($candidateUniqueIdsAfterBoundary);
        self::assertNotEmpty($candidateUniqueIdsAtOrBeforeBoundary);
        self::assertFalse($this->containsAnyUniqueId($resultUniqueIds, $candidateUniqueIdsAtOrBeforeBoundary));
    }

    public function testReturnsEmptyResultForUserWithoutViewAccess(): void
    {
        McpAuthTestHelper::asNoAccessUser(function (): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            $content = McpTestHelper::callToolAndAssertSuccess(
                $server,
                $sessionId,
                ReportList::TOOL_NAME,
                ['idSite' => $this->idSite],
                __METHOD__
            );
            self::assertSame([], $content['reports'] ?? null);
            self::assertSame(false, $content['has_more'] ?? null);
            self::assertSame(null, $content['next_cursor'] ?? null);
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function collectAllReports(Server $server, string $sessionId, int $idSite): array
    {
        $allReports = [];
        $cursor = null;

        do {
            $arguments = ['idSite' => $idSite, 'limit' => 100];
            if (is_string($cursor)) {
                $arguments['cursor'] = $cursor;
            }

            $content = McpTestHelper::callToolAndAssertSuccess(
                $server,
                $sessionId,
                ReportList::TOOL_NAME,
                $arguments,
                __METHOD__ . ':' . (string) count($allReports)
            );

            $pageReports = $content['reports'] ?? [];
            self::assertIsArray($pageReports);
            foreach ($pageReports as $pageReport) {
                self::assertIsArray($pageReport);
                $allReports[] = $pageReport;
            }

            $cursor = $content['next_cursor'] ?? null;
            $hasMore = (bool) ($content['has_more'] ?? false);
        } while ($hasMore === true && is_string($cursor) && $cursor !== '');

        return $allReports;
    }

    /**
     * @param array<int, array<string, mixed>> $reports
     * @return array{name: string, uniqueId: string}
     */
    private function extractNameSortBoundaryFromFirstPage(array $reports): array
    {
        $boundaryIndex = count($reports) - 1;
        $boundary = $reports[$boundaryIndex] ?? null;
        self::assertIsArray($boundary);

        $name = $boundary['name'] ?? null;
        $uniqueId = $boundary['uniqueId'] ?? null;
        self::assertIsString($name);
        self::assertIsString($uniqueId);

        return ['name' => $name, 'uniqueId' => $uniqueId];
    }

    /**
     * @param array<int, array<string, mixed>> $reports
     * @return list<string>
     */
    private function extractUniqueIdsFromToolReports(array $reports): array
    {
        $uniqueIds = [];
        foreach ($reports as $report) {
            $uniqueId = $report['uniqueId'] ?? null;
            self::assertIsString($uniqueId);
            $uniqueIds[] = $uniqueId;
        }

        return $uniqueIds;
    }

    /**
     * @param list<int> $idGoals
     * @return list<string>
     */
    private function collectGoalReportUniqueIdsAfterNameBoundary(
        array $idGoals,
        string $boundaryName,
        string $boundaryUniqueId
    ): array {
        return $this->collectGoalReportUniqueIdsForNameBoundary(
            $idGoals,
            $boundaryName,
            $boundaryUniqueId,
            static fn(int $comparison): bool => $comparison > 0
        );
    }

    /**
     * @param list<int> $idGoals
     * @return list<string>
     */
    private function collectGoalReportUniqueIdsAtOrBeforeNameBoundary(
        array $idGoals,
        string $boundaryName,
        string $boundaryUniqueId
    ): array {
        return $this->collectGoalReportUniqueIdsForNameBoundary(
            $idGoals,
            $boundaryName,
            $boundaryUniqueId,
            static fn(int $comparison): bool => $comparison <= 0
        );
    }

    /**
     * @param list<int> $idGoals
     * @param callable(int): bool $predicate
     * @return list<string>
     */
    private function collectGoalReportUniqueIdsForNameBoundary(
        array $idGoals,
        string $boundaryName,
        string $boundaryUniqueId,
        callable $predicate
    ): array {
        $source = ApiModuleApi::getInstance()->getReportMetadata((string) $this->idSite, false, false, true, true);
        $uniqueIds = [];

        foreach ($source as $report) {
            if (!is_array($report)) {
                continue;
            }
            if ($this->isSubtableMetadataRow($report)) {
                continue;
            }
            if (!$this->isMetadataRowForGoalIds($report, $idGoals)) {
                continue;
            }

            $name = $report['name'] ?? null;
            $uniqueId = $report['uniqueId'] ?? null;
            if (!is_string($name) || !is_string($uniqueId)) {
                continue;
            }

            $comparison = $this->compareNameSortTuple($name, $uniqueId, $boundaryName, $boundaryUniqueId);
            if ($predicate($comparison)) {
                $uniqueIds[] = $uniqueId;
            }
        }

        return $uniqueIds;
    }

    /**
     * @param list<string> $haystack
     * @param list<string> $needles
     */
    private function containsAnyUniqueId(array $haystack, array $needles): bool
    {
        $lookup = [];
        foreach ($haystack as $value) {
            $lookup[$value] = true;
        }

        foreach ($needles as $candidate) {
            if (isset($lookup[$candidate])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $row
     * @param list<int> $idGoals
     */
    private function isMetadataRowForGoalIds(array $row, array $idGoals): bool
    {
        $parameters = $row['parameters'] ?? null;
        if (!is_array($parameters)) {
            return false;
        }

        $goal = $parameters['idGoal'] ?? null;
        if (is_int($goal) && in_array($goal, $idGoals, true)) {
            return true;
        }
        if (is_string($goal) && ctype_digit($goal) && in_array((int) $goal, $idGoals, true)) {
            return true;
        }

        return false;
    }

    /**
     * @param array<mixed> $row
     */
    private function isSubtableMetadataRow(array $row): bool
    {
        $primary = $row['isSubtableReport'] ?? null;
        if ($primary === true || $primary === 1 || $primary === '1') {
            return true;
        }

        $alias = $row['isSubtableReports'] ?? null;
        return $alias === true || $alias === 1 || $alias === '1';
    }

    private function compareNameSortTuple(
        string $leftName,
        string $leftUniqueId,
        string $rightName,
        string $rightUniqueId
    ): int {
        $comparison = strcmp($leftName, $rightName);
        if ($comparison !== 0) {
            return $comparison;
        }

        return strcmp($leftUniqueId, $rightUniqueId);
    }
}
