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
use Piwik\Config;
use Piwik\Plugins\McpServer\Support\Pagination\ApiMethodsPagination;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiListTest extends IntegrationTestCase
{
    public function testReadModeExposesReadOnlyActionsOnly(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

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

        foreach ($content['methods'] as $method) {
            self::assertIsArray($method);
            self::assertArrayHasKey('action', $method);
            self::assertIsString($method['action']);
            $normalizedAction = strtolower($method['action']);
            self::assertTrue(
                str_starts_with($normalizedAction, 'get') || str_starts_with($normalizedAction, 'is'),
                'Read mode returned non-read action: ' . $method['action'],
            );
        }
    }

    public function testFullModeCanReturnMutatingActions(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];

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

    public function testReturnsPagedResultsWithCursor(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

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

    public function testRejectsInvalidLimit(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

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
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

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

    public function testRejectsInvalidCursor(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

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
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];

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
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];

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

    public function testNoneModeHidesAndRejectsToolCall(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'none'];
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
}
