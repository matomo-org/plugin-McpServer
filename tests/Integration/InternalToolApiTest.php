<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Piwik\Access;
use Piwik\API\Proxy;
use Piwik\API\Request as ApiRequest;
use Piwik\Container\StaticContainer;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\API;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\Support\Access\McpAccessLevel;
use Piwik\Plugins\McpServer\Support\Access\McpUnavailableException;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class InternalToolApiTest extends IntegrationTestCase
{
    private string $originalRootApiMethod = '';
    private bool $originalEnableMcp = false;
    private string $originalMaximumAllowedMcpAccessLevel = McpAccessLevel::UNLIMITED;

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
}
