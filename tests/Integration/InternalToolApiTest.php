<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Matomo\Dependencies\McpServer\Mcp\Capability\RegistryInterface;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Piwik\Access;
use Piwik\API\Proxy;
use Piwik\API\Request as ApiRequest;
use Piwik\Container\StaticContainer;
use Piwik\Log\LoggerInterface;
use Piwik\NoAccessException;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\API;
use Piwik\Plugins\McpServer\Contracts\Events\McpServerEvent;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolCallEvent;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\Server\InternalAccess;
use Piwik\Plugins\McpServer\Support\Access\McpAccessLevel;
use Piwik\Plugins\McpServer\Support\Access\McpUnavailableException;
use Piwik\Plugins\McpServer\Support\Api\InternalToolCaller;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Piwik\Version;

/**
 * @group McpServer
 * @group Plugins
 */
class InternalToolApiTest extends IntegrationTestCase
{
    private string $originalRootApiMethod = '';
    private bool $originalEnableMcp = false;
    private string $originalMaximumAllowedMcpAccessLevel = McpAccessLevel::UNLIMITED;
    /** @var list<McpServerEvent> */
    private array $capturedEvents = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->originalRootApiMethod = (string) ApiRequest::getRootApiRequestMethod();
        $settings = $this->settings();
        $this->originalEnableMcp = (bool) $settings->enableMcp->getValue();
        $this->originalMaximumAllowedMcpAccessLevel = $settings->getMaximumAllowedMcpAccessLevel();

        $this->withSuperUser(function () use ($settings): void {
            $settings->enableMcp->setValue(true);
            $settings->maximumMcpAccessLevel->setValue(McpAccessLevel::UNLIMITED);
        });
        ApiRequest::setIsRootRequestApiRequest('');
        API::unsetInstance();

        $this->capturedEvents = [];
        Piwik::addAction('McpServer.serverEvent', function (McpServerEvent $event): void {
            $this->capturedEvents[] = $event;
        });
    }

    public function tearDown(): void
    {
        ApiRequest::setIsRootRequestApiRequest($this->originalRootApiMethod);
        $settings = $this->settings();
        $this->withSuperUser(function () use ($settings): void {
            $settings->enableMcp->setValue($this->originalEnableMcp);
            $settings->maximumMcpAccessLevel->setValue($this->originalMaximumAllowedMcpAccessLevel);
        });
        API::unsetInstance();
        Access::getInstance()->setSuperUserAccess(true);
        parent::tearDown();
    }

    public function testGetInternalToolCatalogReturnsFlatEntries(): void
    {
        $catalog = $this->api()->getInternalToolCatalog();

        self::assertNotEmpty($catalog);
        foreach ($catalog as $entry) {
            self::assertArrayHasKey('name', $entry);
            self::assertArrayHasKey('title', $entry);
            self::assertArrayHasKey('description', $entry);
            self::assertArrayHasKey('inputSchema', $entry);
            self::assertArrayHasKey('outputSchema', $entry);
            self::assertArrayHasKey('readOnly', $entry);
            self::assertArrayHasKey('destructive', $entry);
            self::assertArrayHasKey('idempotent', $entry);
            self::assertArrayHasKey('openWorld', $entry);
            self::assertNotSame('', $entry['name']);
        }
    }

    public function testCallInternalToolSurfacesStructuredContentKey(): void
    {
        Fixture::createWebsite('2020-01-01 00:00:00', 0, 'Internal tool structuredContent site');

        $result = $this->api()->callInternalTool(SiteList::TOOL_NAME, ['limit' => 10]);

        self::assertFalse($result['isError']);
        self::assertArrayHasKey('structuredContent', $result);
    }

    public function testCallInternalToolReturnsIsErrorForUnknownTool(): void
    {
        $result = $this->api()->callInternalTool('matomo_nonexistent_tool', []);

        self::assertTrue($result['isError']);
        self::assertNotEmpty($result['content']);

        $event = $this->singleCapturedToolCallEvent();
        self::assertSame('matomo_nonexistent_tool', $event->toolName);
        self::assertTrue($event->isError);
        self::assertSame(JsonRpcError::INVALID_PARAMS, $event->errorCode);
        self::assertSame('internal', $event->transport);
        self::assertSame('Matomo', $event->clientName);
        self::assertSame(Version::VERSION, $event->clientVersion);
    }

    public function testCallInternalToolDispatchesRegisteredReadOnlyTool(): void
    {
        $idSite = Fixture::createWebsite('2020-01-01 00:00:00', 0, 'Internal tool test site');

        $result = $this->api()->callInternalTool(SiteList::TOOL_NAME, ['limit' => 10]);

        self::assertFalse($result['isError']);
        self::assertNotEmpty($result['content']);

        $first = $result['content'][0];
        self::assertSame('text', $first['type'] ?? null);
        self::assertIsString($first['text'] ?? null);

        $payload = json_decode($first['text'], true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('sites', $payload);
        self::assertIsArray($payload['sites']);

        $ids = array_map(static fn(array $site): int => (int) ($site['idsite'] ?? 0), $payload['sites']);
        self::assertContains($idSite, $ids);

        $event = $this->singleCapturedToolCallEvent();
        self::assertSame(SiteList::TOOL_NAME, $event->toolName);
        self::assertFalse($event->isError);
        self::assertStringStartsWith('matomo-internal-', (string) $event->sessionId);
        self::assertSame('internal', $event->transport);
        self::assertSame('Matomo', $event->clientName);
        self::assertSame(Version::VERSION, $event->clientVersion);
        self::assertGreaterThanOrEqual(0, $event->durationMs);
    }

    public function testCallInternalToolUsesFreshSyntheticSessionWhenSessionKeyIsOmitted(): void
    {
        Fixture::createWebsite('2020-01-01 00:00:00', 0, 'Internal tool ungrouped session site');

        $this->api()->callInternalTool(SiteList::TOOL_NAME, ['limit' => 10]);
        $this->api()->callInternalTool(SiteList::TOOL_NAME, ['limit' => 10]);

        $events = $this->capturedToolCallEvents();
        self::assertCount(2, $events);
        self::assertNotSame($events[0]->sessionId, $events[1]->sessionId);
        self::assertStringStartsWith('matomo-internal-', (string) $events[0]->sessionId);
        self::assertStringStartsWith('matomo-internal-', (string) $events[1]->sessionId);
    }

    public function testCallInternalToolUsesStableSyntheticSessionForSessionKey(): void
    {
        Fixture::createWebsite('2020-01-01 00:00:00', 0, 'Internal tool grouped session site');

        $this->api()->callInternalTool(SiteList::TOOL_NAME, ['limit' => 10], 'workflow-123');
        $this->api()->callInternalTool(SiteList::TOOL_NAME, ['limit' => 10], 'workflow-123');
        $this->api()->callInternalTool(SiteList::TOOL_NAME, ['limit' => 10], 'workflow-456');

        $events = $this->capturedToolCallEvents();
        self::assertCount(3, $events);
        self::assertSame($events[0]->sessionId, $events[1]->sessionId);
        self::assertNotSame($events[0]->sessionId, $events[2]->sessionId);
        self::assertStringNotContainsString('workflow-123', (string) $events[0]->sessionId);
    }

    public function testCallInternalToolPublishesErrorEventAndRethrowsWhenHandlerThrows(): void
    {
        // The real handler chain converts tool throwables into JSON-RPC errors,
        // so the caller's own catch/rethrow guard is only reachable with a
        // handler that throws before that conversion. Stub one to prove the
        // observability event still fires (with an internal-error code) and the
        // original throwable propagates unchanged.
        $boom = new \RuntimeException('handler boom');
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException($boom);
        $access = new InternalAccess($this->createMock(RegistryInterface::class), $handler);

        $caller = new InternalToolCaller($this->createMock(LoggerInterface::class));

        $caught = null;
        try {
            $caller->call($access, 'matomo_exploding_tool', []);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertSame($boom, $caught, 'The handler throwable must propagate unchanged.');

        $event = $this->singleCapturedToolCallEvent();
        self::assertSame('matomo_exploding_tool', $event->toolName);
        self::assertTrue($event->isError);
        self::assertSame(JsonRpcError::INTERNAL_ERROR, $event->errorCode);
        self::assertSame('internal', $event->transport);
        self::assertSame('Matomo', $event->clientName);
    }

    public function testGetInternalToolCatalogIsReachableViaProcessRequest(): void
    {
        // Simulates a controller (or other non-API entry) dispatching the
        // internal method through the API Proxy with the root request marked
        // as non-API. This is the canonical cross-plugin invocation path that
        // real consumers will use.
        $catalog = ApiRequest::processRequest('McpServer.getInternalToolCatalog', []);

        self::assertIsArray($catalog);
        self::assertNotEmpty($catalog);
        foreach ($catalog as $entry) {
            self::assertIsArray($entry);
            self::assertArrayHasKey('name', $entry);
            self::assertArrayHasKey('readOnly', $entry);
            self::assertArrayHasKey('destructive', $entry);
            self::assertArrayHasKey('idempotent', $entry);
        }
    }

    public function testCallInternalToolIsReachableViaProcessRequest(): void
    {
        $idSite = Fixture::createWebsite('2020-01-01 00:00:00', 0, 'Internal tool processRequest site');

        // The API Proxy binds the associative `arguments` array through
        // getArrayParameter, so make sure the same shape is consumable here.
        $result = ApiRequest::processRequest('McpServer.callInternalTool', [
            'name' => SiteList::TOOL_NAME,
            'arguments' => ['limit' => 10],
        ]);

        self::assertIsArray($result);
        self::assertFalse($result['isError']);
        self::assertNotEmpty($result['content']);

        $first = $result['content'][0];
        self::assertSame('text', $first['type'] ?? null);
        $payload = json_decode($first['text'], true);
        self::assertIsArray($payload);
        self::assertArrayHasKey('sites', $payload);

        $ids = array_map(static fn(array $site): int => (int) ($site['idsite'] ?? 0), $payload['sites']);
        self::assertContains($idSite, $ids);
    }

    public function testCallInternalToolOptsOutOfApiProxyInputSanitization(): void
    {
        $className = '\\' . API::class;
        $proxy = Proxy::getInstance();
        $proxy->registerClass($className);

        self::assertTrue(
            $proxy->usesUnsanitizedInputParams($className, 'callInternalTool'),
            'callInternalTool must opt out of API-proxy input sanitization (@unsanitized) so tool '
            . 'arguments reach tools byte-for-byte identical to the HTTP MCP path.',
        );
    }

    public function testGetInternalToolCatalogRejectsBulkRequestProxy(): void
    {
        ApiRequest::setIsRootRequestApiRequest('API.getBulkRequest');

        $this->expectException(NoAccessException::class);
        $this->expectExceptionMessageMatches('/only available to in-process callers/');

        $this->api()->getInternalToolCatalog();
    }

    public function testGetInternalToolCatalogRejectsProcessedReportProxy(): void
    {
        ApiRequest::setIsRootRequestApiRequest('API.getProcessedReport');

        $this->expectException(NoAccessException::class);

        $this->api()->getInternalToolCatalog();
    }

    public function testGetInternalToolCatalogRejectsDirectHttpApiCall(): void
    {
        ApiRequest::setIsRootRequestApiRequest('McpServer.getInternalToolCatalog');

        $this->expectException(NoAccessException::class);
        $this->expectExceptionMessageMatches('/only available to in-process callers/');

        $this->api()->getInternalToolCatalog();
    }

    public function testCallInternalToolRejectsDirectHttpApiCall(): void
    {
        ApiRequest::setIsRootRequestApiRequest('McpServer.callInternalTool');

        $this->expectException(NoAccessException::class);

        $this->api()->callInternalTool('whatever', []);
    }

    public function testRejectsWhenMcpIsDisabled(): void
    {
        $this->withSuperUser(function (): void {
            $this->settings()->enableMcp->setValue(false);
        });

        $this->expectException(McpUnavailableException::class);

        $this->api()->getInternalToolCatalog();
    }

    public function testInternalCatalogIsReachableEvenWhenPrivilegeCeilingWouldBlockHttp(): void
    {
        // Super-admin (rank 4) with VIEW ceiling (rank 1): the HTTP mcp()
        // endpoint rejects this combination (see McpApiEndpointBoundaryTest's
        // privilege-cap tests), but an in-process caller (gated by
        // InternalApiAccessGuard) runs the same user through the internal
        // API and must keep working — the ceiling is an external-token
        // policy, not an in-app gate.
        $this->withSuperUser(function (): void {
            $this->settings()->maximumMcpAccessLevel->setValue(McpAccessLevel::VIEW);
        });

        $catalog = $this->api()->getInternalToolCatalog();

        self::assertNotEmpty($catalog);
    }

    public function testCallInternalToolIsReachableEvenWhenPrivilegeCeilingWouldBlockHttp(): void
    {
        // Same trust-model contract for the tool-call path: lowering the
        // HTTP ceiling must not lock the configuring admin out of their
        // own in-process tool invocations through the internal API.
        Fixture::createWebsite('2020-01-01 00:00:00', 0, 'Internal ceiling-bypass site');
        $this->withSuperUser(function (): void {
            $this->settings()->maximumMcpAccessLevel->setValue(McpAccessLevel::VIEW);
        });

        $result = $this->api()->callInternalTool(SiteList::TOOL_NAME, ['limit' => 10]);

        self::assertFalse($result['isError']);
        self::assertNotEmpty($result['content']);
    }

    private function api(): API
    {
        $api = StaticContainer::get(API::class);
        self::assertInstanceOf(API::class, $api);
        return $api;
    }

    private function settings(): SystemSettings
    {
        $settings = StaticContainer::get(SystemSettings::class);
        self::assertInstanceOf(SystemSettings::class, $settings);
        return $settings;
    }

    private function withSuperUser(callable $callback): void
    {
        $access = Access::getInstance();
        $had = $access->hasSuperUserAccess();
        $access->setSuperUserAccess(true);
        try {
            $callback();
        } finally {
            $access->setSuperUserAccess($had);
        }
    }

    /**
     * @return list<McpToolCallEvent>
     */
    private function capturedToolCallEvents(): array
    {
        return array_values(array_filter(
            $this->capturedEvents,
            static fn (McpServerEvent $event): bool => $event instanceof McpToolCallEvent,
        ));
    }

    private function singleCapturedToolCallEvent(): McpToolCallEvent
    {
        $events = $this->capturedToolCallEvents();
        self::assertCount(1, $events);

        return $events[0];
    }
}
