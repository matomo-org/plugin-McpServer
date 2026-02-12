<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Piwik\Plugins\CustomDimensions\API as CustomDimensionsApi;
use Piwik\Plugins\McpServer\McpTools\DimensionList;
use Piwik\Plugins\McpServer\Support\Pagination\DimensionsPagination;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class DimensionListTest extends IntegrationTestCase
{
    private int $idSite = 0;
    private int $idSiteOther = 0;
    private int $idDimensionAlpha = 0;
    private int $idDimensionBeta = 0;
    private int $idDimensionGamma = 0;

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Dimension Test Site',
            'https://dimension.test'
        );
        $this->idSiteOther = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Dimension Other Test Site',
            'https://dimension-other.test'
        );

        $suffix = substr(hash('sha256', __METHOD__), 0, 8);
        $this->idDimensionAlpha = (int) CustomDimensionsApi::getInstance()->configureNewCustomDimension(
            $this->idSite,
            'MCP Dimension Alpha ' . $suffix,
            'visit',
            1
        );
        $this->idDimensionBeta = (int) CustomDimensionsApi::getInstance()->configureNewCustomDimension(
            $this->idSite,
            'MCP Dimension Beta ' . $suffix,
            'action',
            1
        );
        $this->idDimensionGamma = (int) CustomDimensionsApi::getInstance()->configureNewCustomDimension(
            $this->idSite,
            'MCP Dimension Gamma ' . $suffix,
            'visit',
            1
        );
        CustomDimensionsApi::getInstance()->configureNewCustomDimension(
            $this->idSite,
            'MCP Dimension Inactive ' . $suffix,
            'action',
            0
        );
    }

    public function testReturnsPagedResults(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2],
            __METHOD__ . '#1'
        );

        self::assertIsArray($firstPage['dimensions'] ?? null);
        self::assertCount(2, $firstPage['dimensions']);
        self::assertTrue($firstPage['has_more']);
        self::assertIsString($firstPage['next_cursor']);

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'cursor' => $firstPage['next_cursor']],
            __METHOD__ . '#2'
        );

        self::assertIsArray($secondPage['dimensions'] ?? null);
        self::assertCount(1, $secondPage['dimensions']);
        self::assertFalse($secondPage['has_more']);
        self::assertNull($secondPage['next_cursor']);
    }

    public function testReturnsOnlyActiveDimensions(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 25],
            __METHOD__
        );

        $dimensions = $content['dimensions'] ?? null;
        self::assertIsArray($dimensions);
        self::assertNotEmpty($dimensions);
        $ids = array_map(static fn(array $row): int => (int) ($row['iddimension'] ?? 0), $dimensions);
        self::assertContains($this->idDimensionAlpha, $ids);
        self::assertContains($this->idDimensionBeta, $ids);
        self::assertContains($this->idDimensionGamma, $ids);
        foreach ($dimensions as $dimension) {
            self::assertArrayHasKey('iddimension', $dimension);
            self::assertArrayHasKey('name', $dimension);
            self::assertArrayHasKey('scope', $dimension);
        }
    }

    public function testSupportsSortByIdDesc(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 3, 'sort' => DimensionsPagination::SORT_ID_DESC],
            __METHOD__
        );
        $dimensions = $content['dimensions'] ?? null;
        self::assertIsArray($dimensions);
        self::assertCount(3, $dimensions);
        self::assertGreaterThan($dimensions[1]['iddimension'] ?? 0, $dimensions[0]['iddimension'] ?? 0);
    }

    public function testIdPaginationIncludesNewRowsAddedAfterFirstPageWhenTheySortAfterCursor(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'sort' => DimensionsPagination::SORT_ID_ASC],
            __METHOD__ . '#1'
        );
        $dimensions = $firstPage['dimensions'] ?? null;
        self::assertIsArray($dimensions);
        self::assertCount(2, $dimensions);
        self::assertIsString($firstPage['next_cursor'] ?? null);

        $newDimensionId = (int) CustomDimensionsApi::getInstance()->configureNewCustomDimension(
            $this->idSite,
            'MCP Dimension Delta ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'visit',
            1
        );

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'limit' => 2,
                'sort' => DimensionsPagination::SORT_ID_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2'
        );
        $dimensions = $secondPage['dimensions'] ?? null;
        self::assertIsArray($dimensions);
        self::assertCount(2, $dimensions);
        $ids = array_map(static fn(array $row): int => (int) ($row['iddimension'] ?? 0), $dimensions);
        self::assertContains($newDimensionId, $ids);
    }

    public function testNamePaginationDoesNotBackfillRowsAddedBeforeCursorBoundary(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 2, 'sort' => DimensionsPagination::SORT_NAME_ASC],
            __METHOD__ . '#1'
        );
        $dimensions = $firstPage['dimensions'] ?? null;
        self::assertIsArray($dimensions);
        self::assertCount(2, $dimensions);
        self::assertIsString($firstPage['next_cursor'] ?? null);

        CustomDimensionsApi::getInstance()->configureNewCustomDimension(
            $this->idSite,
            'MCP Dimension Aaron ' . substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8),
            'visit',
            1
        );

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'limit' => 2,
                'sort' => DimensionsPagination::SORT_NAME_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2'
        );
        $dimensions = $secondPage['dimensions'] ?? null;
        self::assertIsArray($dimensions);
        self::assertCount(1, $dimensions);
        self::assertSame($this->idDimensionGamma, (int) ($dimensions[0]['iddimension'] ?? 0));
    }

    public function testRejectsInvalidLimit(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 0],
            __METHOD__
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . DimensionList::TOOL_NAME . "':",
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
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'sort' => 'invalid'],
            __METHOD__
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . DimensionList::TOOL_NAME . "':",
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
            DimensionList::TOOL_NAME,
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
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 1, 'sort' => DimensionsPagination::SORT_ID_DESC],
            __METHOD__ . '#1'
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'cursor' => $nextCursor, 'sort' => DimensionsPagination::SORT_NAME_ASC],
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
            DimensionList::TOOL_NAME,
            ['idSite' => $this->idSite, 'limit' => 1, 'sort' => DimensionsPagination::SORT_NAME_ASC],
            __METHOD__ . '#1'
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            DimensionList::TOOL_NAME,
            [
                'idSite' => $this->idSiteOther,
                'cursor' => $nextCursor,
                'sort' => DimensionsPagination::SORT_NAME_ASC,
            ],
            'Invalid cursor.',
            __METHOD__ . '#2'
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
                DimensionList::TOOL_NAME,
                ['idSite' => $this->idSite],
                __METHOD__
            );
            self::assertSame([], $content['dimensions'] ?? null);
            self::assertSame(false, $content['has_more'] ?? null);
            self::assertSame(null, $content['next_cursor'] ?? null);
        });
    }
}
