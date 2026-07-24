<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit;

use Matomo\Dependencies\McpServer\Http\Discovery\Psr17Factory;
use Matomo\Dependencies\McpServer\Mcp\Schema\Enum\ProtocolVersion;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Transport\StreamableHttpTransport;
use Matomo\Dependencies\McpServer\Psr\Http\Message\ResponseInterface;
use PHPUnit\Framework\TestCase;
use Piwik\Config;
use Piwik\Log\LoggerInterface;
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Piwik\Plugins\McpServer\tests\Framework\FixedMcpToolsProvider;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Psr\Container\ContainerInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class McpServerFactoryTest extends TestCase
{
    /** @var array<mixed>|null */
    private ?array $originalMcpServerConfig = null;

    /** @var array<mixed>|null */
    private ?array $originalGeneralConfig = null;

    /** @var array<string, string|null> */
    private array $originalServerHostKeys = [];

    public function setUp(): void
    {
        parent::setUp();

        $originalConfig = Config::getInstance()->McpServer ?? null;
        $this->originalMcpServerConfig = is_array($originalConfig) ? $originalConfig : null;

        $originalGeneral = Config::getInstance()->General ?? null;
        $this->originalGeneralConfig = is_array($originalGeneral) ? $originalGeneral : null;

        foreach (['HTTP_HOST', 'HTTP_X_FORWARDED_HOST'] as $key) {
            $value = $_SERVER[$key] ?? null;
            $this->originalServerHostKeys[$key] = is_string($value) ? $value : null;
        }
    }

    public function tearDown(): void
    {
        Config::getInstance()->McpServer = $this->originalMcpServerConfig;
        Config::getInstance()->General = $this->originalGeneralConfig;

        foreach ($this->originalServerHostKeys as $key => $value) {
            if ($value === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $value;
            }
        }

        parent::tearDown();
    }

    public function testInitializeResponseHasExpectedServerInfoAndCapabilities(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 1];

        $factory = new McpServerFactory(
            $this->createMock(LoggerInterface::class),
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $payload = McpTestHelper::makeInitializeRequest('init-1');

        $response = McpTestHelper::postJson($server, $payload);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseInitialize($message);
        $pluginVersion = (string) Manager::getInstance()->getVersion('McpServer');

        self::assertSame('Matomo MCP Server', $result->serverInfo->name);
        self::assertSame($pluginVersion, $result->serverInfo->version);

        $capabilities = $result->capabilities;

        self::assertTrue($capabilities->tools);
        self::assertNull($capabilities->toolsListChanged);
        self::assertFalse($capabilities->resources);
        self::assertNull($capabilities->resourcesSubscribe);
        self::assertNull($capabilities->resourcesListChanged);
        self::assertFalse($capabilities->prompts);
        self::assertNull($capabilities->promptsListChanged);
        self::assertFalse($capabilities->logging);
        self::assertFalse($capabilities->completions);
    }

    public function testToolCallLoggingEnabledInjectsObservedHandler(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 1];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testStringOneConfigEnablesFullParameterLogging(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => '1',
            'log_tool_call_parameters_full' => '1',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug')
            ->with(
                self::callback(static function (string $message): bool {
                    return $message === 'MCP Tool Call failed: {error_message} '
                        . '[{formatted_arguments}] [session={session_id}]';
                }),
                self::callback(static fn(mixed $context): bool => is_array($context)
                    && ($context['error_message'] ?? null) === 'Tool not found: "missing_tool".'
                    && ($context['formatted_arguments'] ?? null) === 'query: "secret"'),
            );

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', ['query' => 'secret'], 'missing-full-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testBooleanTrueConfigEnablesToolCallLogging(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => true];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-bool-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testToolCallLoggingDisabledSkipsObservedHandlerInjection(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 0];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-2');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testStringZeroConfigDisablesToolCallLogging(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => '0'];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-string-zero-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testStringTrueConfigDoesNotEnableToolCallLogging(): void
    {
        Config::getInstance()->McpServer = ['log_tool_calls' => 'true'];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-string-true-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testToolCallLoggingMissingConfigSkipsObservedHandlerInjection(): void
    {
        Config::getInstance()->McpServer = [];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())
            ->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-3');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testConfiguredWarnLevelUsesWarning(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => 'warn',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $logger->expects(self::never())->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-warn-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testConfiguredErrorLevelUsesError(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => 'ERROR',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $logger->expects(self::never())->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-error-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testConfiguredInfoLevelUsesInfo(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => ' INFO ',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info');
        $logger->expects(self::never())->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-info-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testConfiguredVerboseLevelUsesDebug(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => 'VERBOSE',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-verbose-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testInvalidToolCallLogLevelFallsBackToDebug(): void
    {
        Config::getInstance()->McpServer = [
            'log_tool_calls' => 1,
            'log_tool_call_level' => 'TRACE',
        ];

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('debug');
        $logger->expects(self::never())->method('info');
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::never())->method('error');

        $factory = new McpServerFactory(
            $logger,
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();
        $sessionId = McpTestHelper::initializeSession($server);

        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-invalid-level-1');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::METHOD_NOT_FOUND, $message->code);
    }

    public function testTransportRejectsUntrustedHostWhenTrustedHostsConfigured(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['analytics.example.com']]);
        $this->setRequestHost('attacker.example.net');

        $response = $this->runInitialize();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Host header', $this->body($response));
    }

    public function testTransportAllowsTrustedHost(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['analytics.example.com']]);
        $this->setRequestHost('analytics.example.com');

        $response = $this->runInitialize();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTransportAllowsSupportedProtocolVersion(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['analytics.example.com']]);
        $this->setRequestHost('analytics.example.com');

        $response = $this->runInitialize(protocolVersion: ProtocolVersion::V2025_11_25->value);

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTransportRejectsUnsupportedProtocolVersion(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['analytics.example.com']]);
        $this->setRequestHost('analytics.example.com');

        $response = $this->runInitialize(protocolVersion: '2099-01-01');

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(JsonRpcError::INVALID_PARAMS, McpTestHelper::decodeError($response)->code);
    }

    public function testTransportAllowsAnyHostWhenTrustedHostsNotConfigured(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => []]);
        $this->setRequestHost('anything.example.net');

        $response = $this->runInitialize();

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * No-Origin native client with host validation globally disabled: the
     * no-Origin branch delegates to {@see \Piwik\Url::isValidHost()}, which
     * returns true unconditionally, so the request is accepted.
     */
    public function testTransportAllowsNoOriginClientWhenTrustedHostCheckDisabled(): void
    {
        $this->setGeneralConfig([
            'trusted_hosts' => ['analytics.example.com'],
            'enable_trusted_host_check' => 0,
        ]);
        $this->setRequestHost('anything.example.net');

        $response = $this->runInitialize();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTransportAllowsForwardedHostBehindProxy(): void
    {
        $this->setGeneralConfig([
            'trusted_hosts' => ['analytics.example.com'],
            'proxy_host_headers' => ['HTTP_X_FORWARDED_HOST'],
        ]);
        // A Host-rewriting proxy delivers the backend name in Host while the
        // real client-facing (trusted) host arrives in X-Forwarded-Host.
        $this->setRequestHost('backend-internal', 'analytics.example.com');

        $response = $this->runInitialize();

        self::assertSame(200, $response->getStatusCode());
    }

    public function testTransportRejectsUntrustedForwardedHostBehindProxy(): void
    {
        $this->setGeneralConfig([
            'trusted_hosts' => ['analytics.example.com'],
            'proxy_host_headers' => ['HTTP_X_FORWARDED_HOST'],
        ]);
        // DNS-rebinding attempt reaching Matomo through the proxy: the attacker
        // host surfaces in the proxy-aware forwarded host and must be rejected.
        $this->setRequestHost('backend-internal', 'evil.example.net');

        $response = $this->runInitialize();

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Host header', $this->body($response));
    }

    /**
     * A supplied Origin whose host is a trusted deployment hostname is a
     * same-deployment request, not a DNS-rebinding attack, so it continues.
     * Accepting it is request validation, not CORS support.
     */
    public function testTransportAllowsOriginMatchingTrustedHost(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['analytics.example.com']]);
        $this->setRequestHost('analytics.example.com');

        $response = $this->runInitialize('https://analytics.example.com');

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * A DNS-rebinding attempt arrives with the attacker's origin, outside the
     * deployment's own hostnames, and is rejected.
     */
    public function testTransportRejectsUntrustedOrigin(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['analytics.example.com']]);
        $this->setRequestHost('analytics.example.com');

        $response = $this->runInitialize('https://evil.example.net');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Origin header', $this->body($response));
    }

    /**
     * A browser origin is per-host: a subdomain of a trusted host is a distinct
     * origin, so the exact match rejects it (unlike
     * {@see \Piwik\Url::isValidHost()}'s subdomain-permissive regex).
     */
    public function testTransportRejectsSubdomainOfTrustedHostOrigin(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['example.com']]);
        $this->setRequestHost('example.com');

        $response = $this->runInitialize('https://analytics.example.com');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Origin header', $this->body($response));
    }

    /**
     * Lookalike prefix (evilexample.com) and suffix spoof
     * (example.com.attacker.test) both share text with the trusted host but are
     * not equal to it, so exact matching rejects them.
     */
    public function testTransportRejectsLookalikeOrigins(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['example.com']]);
        $this->setRequestHost('example.com');

        self::assertSame(403, $this->runInitialize('https://evilexample.com')->getStatusCode());
        self::assertSame(403, $this->runInitialize('https://example.com.attacker.test')->getStatusCode());
    }

    /**
     * A trailing dot on the Origin host (absolute FQDN form) normalizes to the
     * same value as the configured host and is accepted.
     */
    public function testTransportAllowsTrailingDotOrigin(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['analytics.example.com']]);
        $this->setRequestHost('analytics.example.com');

        $response = $this->runInitialize('https://analytics.example.com./');

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * `cors_domains` governs Matomo's CORS response headers (emitted by core),
     * not who may reach the MCP endpoint: an Origin allowed for CORS but outside
     * trusted_hosts is still rejected. Accepting an Origin is request
     * validation, not CORS support — see {@see McpServerFactory::createTransport()}.
     */
    public function testTransportCorsDomainsDoesNotAuthorizeMcpOrigin(): void
    {
        $this->setGeneralConfig([
            'trusted_hosts' => ['analytics.example.com'],
            'cors_domains' => ['https://app.example.com'],
        ]);
        $this->setRequestHost('analytics.example.com');

        $response = $this->runInitialize('https://app.example.com');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Origin header', $this->body($response));
    }

    /**
     * No trusted-host policy (unconfigured instance): the deployment's own
     * hostnames are unknown, so a supplied Origin fails closed — even though the
     * no-Origin branch stays permissive via {@see \Piwik\Url::isValidHost()}
     * (see {@see testTransportAllowsAnyHostWhenTrustedHostsNotConfigured}).
     */
    public function testTransportRejectsOriginWhenNoTrustedHostsConfigured(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => []]);
        $this->setRequestHost('anything.example.net');

        $response = $this->runInitialize('https://anything.example.net');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Origin header', $this->body($response));
    }

    /**
     * An empty allowlist fails closed for a supplied Origin regardless of
     * `enable_trusted_host_check`: that flag only relaxes the no-Origin branch
     * (see {@see testTransportAllowsNoOriginClientWhenTrustedHostCheckDisabled}),
     * and the empty-allowlist rejection short-circuits before the flag is ever
     * consulted, so disabling the host check must not open a supplied Origin.
     */
    public function testTransportRejectsOriginWhenNoTrustedHostsConfiguredAndHostCheckDisabled(): void
    {
        $this->setGeneralConfig([
            'trusted_hosts' => [],
            'enable_trusted_host_check' => 0,
        ]);
        $this->setRequestHost('anything.example.net');

        $response = $this->runInitialize('https://anything.example.net');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Origin header', $this->body($response));
    }

    /**
     * The general host-check flag governs the no-Origin native branch only; it
     * must not waive supplied-Origin validation. A trusted Origin is still
     * accepted, an untrusted one still rejected, under this setting.
     */
    public function testTransportValidatesOriginWhenTrustedHostCheckDisabled(): void
    {
        $this->setGeneralConfig([
            'trusted_hosts' => ['analytics.example.com'],
            'enable_trusted_host_check' => 0,
        ]);
        $this->setRequestHost('analytics.example.com');

        self::assertSame(200, $this->runInitialize('https://analytics.example.com')->getStatusCode());
        self::assertSame(403, $this->runInitialize('https://elsewhere.example.net')->getStatusCode());
    }

    /**
     * No implicit localhost allowlist: unlike {@see \Piwik\Url::getAlwaysTrustedHosts()},
     * localhost is validated as an Origin only when explicitly configured.
     */
    public function testTransportRejectsLocalhostOriginUnlessConfigured(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['analytics.example.com']]);
        $this->setRequestHost('analytics.example.com');

        $response = $this->runInitialize('http://localhost');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Origin header', $this->body($response));
    }

    public function testTransportAllowsLocalhostOriginWhenConfigured(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['localhost']]);
        $this->setRequestHost('localhost');

        $response = $this->runInitialize('http://localhost');

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * The literal `Origin: null` (sandboxed iframes, file://, some redirects)
     * has no host, so it cannot match a trusted host and fails closed.
     */
    public function testTransportRejectsLiteralNullOrigin(): void
    {
        $this->setGeneralConfig(['trusted_hosts' => ['analytics.example.com']]);
        $this->setRequestHost('analytics.example.com');

        $response = $this->runInitialize('null');

        self::assertSame(403, $response->getStatusCode());
        self::assertStringContainsString('Invalid Origin header', $this->body($response));
    }

    /**
     * @param array<string, mixed> $general
     */
    private function setGeneralConfig(array $general): void
    {
        $current = Config::getInstance()->General;
        $current = is_array($current) ? $current : [];

        // The ddev/test config sets trusted_hosts and proxy_host_headers in
        // [General]; clear the transport-relevant keys so each test controls
        // them explicitly rather than inheriting environment defaults.
        unset(
            $current['trusted_hosts'],
            $current['enable_trusted_host_check'],
            $current['proxy_host_headers'],
            $current['cors_domains'],
        );

        Config::getInstance()->General = array_merge($current, $general);
    }

    /**
     * The host middleware resolves the request host from $_SERVER the same way
     * Matomo does (proxy-aware), not from the PSR-7 request, so the incoming
     * host is simulated here rather than in the request URI.
     */
    private function setRequestHost(string $host, ?string $forwardedHost = null): void
    {
        $_SERVER['HTTP_HOST'] = $host;

        if ($forwardedHost === null) {
            unset($_SERVER['HTTP_X_FORWARDED_HOST']);
        } else {
            $_SERVER['HTTP_X_FORWARDED_HOST'] = $forwardedHost;
        }
    }

    private function runInitialize(?string $origin = null, ?string $protocolVersion = null): ResponseInterface
    {
        $factory = new McpServerFactory(
            $this->createMock(LoggerInterface::class),
            new InMemorySessionStore(),
            $this->createMock(ContainerInterface::class),
            new ToolCallParameterFormatter(),
            new FixedMcpToolsProvider([]),
        );
        $server = $factory->createServer();

        $psr = new Psr17Factory();
        $request = $psr->createServerRequest('POST', 'https://mcp.test/mcp')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($psr->createStream(McpTestHelper::makeInitializeRequest('host-check-1')));

        if ($origin !== null) {
            $request = $request->withHeader('Origin', $origin);
        }

        if ($protocolVersion !== null) {
            $request = $request->withHeader(StreamableHttpTransport::PROTOCOL_VERSION_HEADER, $protocolVersion);
        }

        return $server->run(McpServerFactory::createTransport($request));
    }

    private function body(ResponseInterface $response): string
    {
        $body = $response->getBody();

        if ($body->isSeekable()) {
            $body->rewind();
        }

        return $body->getContents();
    }
}
