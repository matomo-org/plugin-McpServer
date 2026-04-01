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
use Piwik\Plugins\McpServer\Support\Pagination\ApiMethodsPagination;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiListTest extends IntegrationTestCase
{
    private string $originalRawApiAccessMode = 'none';

    public function setUp(): void
    {
        parent::setUp();

        $this->originalRawApiAccessMode = McpTestHelper::getRawApiAccessMode();
    }

    public function tearDown(): void
    {
        McpTestHelper::setRawApiAccessMode($this->originalRawApiAccessMode);

        parent::tearDown();
    }

    public function testReadModeExposesOnlyReadClassifiedMethods(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['limit' => 500],
        );

        self::assertArrayHasKey('methods', $content);
        self::assertIsArray($content['methods']);
        self::assertNotEmpty($content['methods']);

        $methods = [];
        $foundNonPrefixReadMethod = false;

        foreach ($content['methods'] as $method) {
            self::assertIsArray($method);
            self::assertArrayHasKey('method', $method);
            self::assertIsString($method['method']);
            self::assertArrayHasKey('action', $method);
            self::assertIsString($method['action']);
            self::assertSame('read', $method['operationCategory'] ?? null);
            self::assertArrayNotHasKey('classificationConfidence', $method);
            self::assertArrayNotHasKey('classificationReason', $method);

            $methods[] = $method['method'];

            $normalizedAction = strtolower($method['action']);
            if (!str_starts_with($normalizedAction, 'get') && !str_starts_with($normalizedAction, 'is')) {
                $foundNonPrefixReadMethod = true;
            }
        }

        self::assertContains('UsersManager.hasSuperUserAccess', $methods);
        self::assertTrue($foundNonPrefixReadMethod, 'Expected at least one read-classified non-get/is action.');
    }

    public function testFullModeCanReturnMutatingActions(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['search' => 'add', 'limit' => 500],
        );

        self::assertArrayHasKey('methods', $content);
        self::assertIsArray($content['methods']);
        self::assertNotEmpty($content['methods']);

        $foundMutatingAction = false;
        foreach ($content['methods'] as $method) {
            if (!is_array($method) || !is_string($method['action'] ?? null)) {
                continue;
            }

            $normalizedAction = strtolower($method['action']);
            if (!str_starts_with($normalizedAction, 'get') && !str_starts_with($normalizedAction, 'is')) {
                $foundMutatingAction = true;
                break;
            }
        }

        self::assertTrue($foundMutatingAction, 'Expected at least one non-read action in full mode.');
    }

    public function testReadModeHidesBlockedProxyLikeMethods(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $methods = $this->listMethodsForCurrentConfig(500);

        self::assertContains('API.getSuggestedValuesForSegment', $methods);
        self::assertNotContains('API.get', $methods);
        self::assertNotContains('API.getBulkRequest', $methods);
        self::assertNotContains('API.getMetadata', $methods);
        self::assertNotContains('API.getProcessedReport', $methods);
        self::assertNotContains('API.getReportMetadata', $methods);
        self::assertNotContains('API.getRowEvolution', $methods);
        self::assertNotContains('ImageGraph.get', $methods);
        self::assertNotContains('Insights.getInsights', $methods);
        self::assertNotContains('Insights.getMoversAndShakers', $methods);
        self::assertNotContains('TreemapVisualization.getTreemapData', $methods);
    }

    public function testReadModeAllowsMediumConfidenceReadMethods(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $methods = $this->listMethodsForCurrentConfig(500);

        self::assertContains('UsersManager.getUsers', $methods);
        self::assertContains('UsersManager.hasSuperUserAccess', $methods);
    }

    public function testFullModeHidesInternalAnnotatedMethods(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $methods = $this->listMethodsForCurrentConfig(500);

        self::assertNotContains('SitesManager.getMessagesToWarnOnSiteRemoval', $methods);
        self::assertNotContains('JsTrackerInstallCheck.wasJsTrackerInstallTestSuccessful', $methods);
        self::assertNotContains('JsTrackerInstallCheck.initiateJsTrackerInstallTest', $methods);
    }

    public function testFullModeKeepsHideExceptForSuperUserMethodsVisibleForSuperUser(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $methods = $this->listMethodsForCurrentConfig(500);

        self::assertContains('CoreAdminHome.runScheduledTasks', $methods);
    }

    public function testUpdateModeCanReturnUpdateActions(): void
    {
        McpTestHelper::setRawApiAccessMode('update');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['search' => 'setdefaulttimezone', 'limit' => 50],
            __METHOD__,
        );
        $methodsData = $content['methods'] ?? null;
        self::assertIsArray($methodsData);

        $methods = array_map(
            static fn(array $row): string => (string) ($row['method'] ?? ''),
            $methodsData,
        );

        self::assertContains('SitesManager.setDefaultTimezone', $methods);
    }

    public function testCreateModeCanReturnCreateActions(): void
    {
        McpTestHelper::setRawApiAccessMode('create');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['search' => 'adduser', 'limit' => 20],
            __METHOD__,
        );

        $methods = $content['methods'] ?? null;
        self::assertIsArray($methods);
        self::assertNotEmpty($methods);
        self::assertContains('UsersManager.addUser', array_values(array_map(
            static fn(array $row): string => (string) ($row['method'] ?? ''),
            $methods,
        )));
    }

    public function testDeleteModeCanReturnDeleteActions(): void
    {
        McpTestHelper::setRawApiAccessMode('delete');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['search' => 'deletesite', 'limit' => 20],
            __METHOD__,
        );

        $methods = $content['methods'] ?? null;
        self::assertIsArray($methods);
        self::assertNotEmpty($methods);
        self::assertContains('SitesManager.deleteSite', array_values(array_map(
            static fn(array $row): string => (string) ($row['method'] ?? ''),
            $methods,
        )));
    }

    public function testFullModeHidesBlockedProxyLikeMethods(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $methods = $this->listMethodsForCurrentConfig(500);

        self::assertContains('API.getSuggestedValuesForSegment', $methods);
        self::assertNotContains('API.get', $methods);
        self::assertNotContains('API.getBulkRequest', $methods);
        self::assertNotContains('API.getMetadata', $methods);
        self::assertNotContains('API.getProcessedReport', $methods);
        self::assertNotContains('API.getReportMetadata', $methods);
        self::assertNotContains('API.getRowEvolution', $methods);
        self::assertNotContains('ImageGraph.get', $methods);
        self::assertNotContains('Insights.getInsights', $methods);
        self::assertNotContains('Insights.getMoversAndShakers', $methods);
        self::assertNotContains('TreemapVisualization.getTreemapData', $methods);
    }

    public function testReturnsPagedResultsWithCursor(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['limit' => 2, 'sort' => ApiMethodsPagination::SORT_METHOD_ASC],
            __METHOD__ . '#1',
        );

        self::assertIsArray($firstPage['methods'] ?? null);
        self::assertCount(2, $firstPage['methods']);
        self::assertTrue($firstPage['has_more']);
        self::assertIsString($firstPage['next_cursor']);
        self::assertGreaterThanOrEqual(3, $firstPage['total_rows'] ?? 0);

        $secondPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            [
                'limit' => 2,
                'sort' => ApiMethodsPagination::SORT_METHOD_ASC,
                'cursor' => $firstPage['next_cursor'],
            ],
            __METHOD__ . '#2',
        );

        self::assertIsArray($secondPage['methods'] ?? null);
        self::assertNotEmpty($secondPage['methods']);
        self::assertSame($firstPage['total_rows'] ?? null, $secondPage['total_rows'] ?? null);

        $firstPageMethods = array_map(
            static fn(array $row): string => (string) ($row['method'] ?? ''),
            $firstPage['methods'],
        );
        $secondPageMethods = array_map(
            static fn(array $row): string => (string) ($row['method'] ?? ''),
            $secondPage['methods'],
        );
        self::assertSame([], array_values(array_intersect($firstPageMethods, $secondPageMethods)));
    }

    public function testCategoryFilterReturnsOnlyClassifiedCrudMatches(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['category' => 'create', 'limit' => 100],
            __METHOD__,
        );

        $methods = $content['methods'] ?? null;
        self::assertIsArray($methods);
        self::assertNotEmpty($methods);

        foreach ($methods as $method) {
            self::assertSame('create', $method['operationCategory'] ?? null);
            self::assertArrayNotHasKey('classificationConfidence', $method);
            self::assertArrayNotHasKey('classificationReason', $method);
        }
    }

    public function testCategoryFilterCanExcludeLowConfidenceMethods(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['category' => 'update', 'search' => 'sendreport', 'limit' => 10],
            __METHOD__,
        );

        self::assertSame([], $content['methods'] ?? null);
    }

    public function testUncategorizedCategoryFilterReturnsOnlyUnclassifiedMethods(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['category' => 'uncategorized', 'search' => 'sendreport', 'limit' => 10],
            __METHOD__,
        );

        $methods = $content['methods'] ?? null;
        self::assertIsArray($methods);
        self::assertNotEmpty($methods);

        foreach ($methods as $method) {
            self::assertNull($method['operationCategory'] ?? null);
            self::assertArrayNotHasKey('classificationConfidence', $method);
            self::assertArrayNotHasKey('classificationReason', $method);
        }
    }

    public function testRejectsInvalidLimit(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            'matomo_api_list',
            ['limit' => 0],
            __METHOD__,
        );

        self::assertStringContainsString("Invalid parameters for tool 'matomo_api_list':", $message->message ?? '');
        self::assertStringContainsString('limit', $message->message ?? '');
    }

    public function testRejectsInvalidSort(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            'matomo_api_list',
            ['sort' => 'invalid'],
            __METHOD__,
        );

        self::assertStringContainsString("Invalid parameters for tool 'matomo_api_list':", $message->message ?? '');
        self::assertStringContainsString('sort', $message->message ?? '');
    }

    public function testRejectsInvalidCategory(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            'matomo_api_list',
            ['category' => 'unsupported'],
            __METHOD__,
        );

        self::assertStringContainsString("Invalid parameters for tool 'matomo_api_list':", $message->message ?? '');
        self::assertStringContainsString('category', $message->message ?? '');
    }

    public function testRejectsReportsCategory(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            'matomo_api_list',
            ['category' => 'reports'],
            __METHOD__,
        );

        self::assertStringContainsString("Invalid parameters for tool 'matomo_api_list':", $message->message ?? '');
        self::assertStringContainsString('category', $message->message ?? '');
    }

    public function testRejectsInvalidCursor(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            'matomo_api_list',
            ['cursor' => 'invalid'],
            'Invalid cursor.',
            __METHOD__,
        );
    }

    public function testRejectsCursorSortMismatch(): void
    {
        McpTestHelper::setRawApiAccessMode('read');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['limit' => 1, 'sort' => ApiMethodsPagination::SORT_METHOD_DESC],
            __METHOD__ . '#1',
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            'matomo_api_list',
            ['cursor' => $nextCursor, 'sort' => ApiMethodsPagination::SORT_METHOD_ASC],
            'Invalid cursor.',
            __METHOD__ . '#2',
        );
    }

    public function testRejectsCursorFromDifferentFilterContext(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['limit' => 1, 'sort' => ApiMethodsPagination::SORT_METHOD_ASC, 'search' => 'get'],
            __METHOD__ . '#1',
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            'matomo_api_list',
            ['cursor' => $nextCursor, 'sort' => ApiMethodsPagination::SORT_METHOD_ASC, 'search' => 'add'],
            'Invalid cursor.',
            __METHOD__ . '#2',
        );
    }

    public function testRejectsCursorFromDifferentCategoryContext(): void
    {
        McpTestHelper::setRawApiAccessMode('full');

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $firstPage = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['limit' => 1, 'sort' => ApiMethodsPagination::SORT_METHOD_ASC, 'category' => 'read'],
            __METHOD__ . '#1',
        );
        $nextCursor = $firstPage['next_cursor'] ?? null;
        self::assertIsString($nextCursor);

        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            'matomo_api_list',
            ['cursor' => $nextCursor, 'sort' => ApiMethodsPagination::SORT_METHOD_ASC, 'category' => 'create'],
            'Invalid cursor.',
            __METHOD__ . '#2',
        );
    }

    public function testNoneModeHidesAndRejectsToolCall(): void
    {
        McpTestHelper::setRawApiAccessMode('none');
        self::assertNotContains('matomo_api_list', $this->listToolNamesForCurrentConfig());

        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest('matomo_api_list', [], __METHOD__);
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    /**
     * @return list<string>
     */
    private function listToolNamesForCurrentConfig(): array
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest(__METHOD__);

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        return array_values(array_map(static fn($tool) => $tool->name, $result->tools));
    }

    /**
     * @return list<string>
     */
    private function listMethodsForCurrentConfig(int $limit): array
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            'matomo_api_list',
            ['limit' => $limit],
            __METHOD__,
        );

        $methods = $content['methods'] ?? null;
        self::assertIsArray($methods);

        return array_values(array_map(
            static fn(array $row): string => (string) ($row['method'] ?? ''),
            $methods,
        ));
    }
}
