<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Server\Handler\Request;

use Matomo\Dependencies\McpServer\Mcp\Capability\Registry;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ReferenceHandler;
use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\CallToolHandler;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\Session;
use Matomo\Dependencies\McpServer\Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\TestCase;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Server\Handler\Request\ObservedCallToolHandler;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;
use Psr\Log\NullLogger;

/**
 * @group McpServer
 * @group Plugins
 */
class ObservedCallToolHandlerTest extends TestCase
{
    public function testSuccessLogsTelemetryContextAndReturnsResponse(): void
    {
        $registry = new Registry();
        $logger = $this->createMock(LoggerInterface::class);
        $session = $this->createSession('87f14d0f-7d95-4a76-b2db-bf0f1ca6f3a1');
        $request = (new CallToolRequest('demo_tool', ['id' => 4, 'query' => 'abc']))->withId('r-1');

        $registry->registerTool(
            $this->createTool('demo_tool', [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'query' => ['type' => 'string'],
                ],
                'required' => [],
            ]),
            static fn(int $id, string $query): array => ['ok' => $id === 4 && $query === 'abc']
        );

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                self::callback(static function (string $message): bool {
                    return str_starts_with(
                        $message,
                        'MCP Tool Call successful: demo_tool [id: <int>, query: <string:3>]'
                    ) && str_contains($message, '[session=87f14d0f-7d95-4a76-b2db-bf0f1ca6f3a1, response_bytes=');
                }),
                self::callback(static function (array $context): bool {
                    return isset(
                        $context['mcp_session_id'],
                        $context['mcp_tool_name'],
                        $context['mcp_params_mode'],
                        $context['mcp_response_bytes']
                    )
                        && $context['mcp_session_id'] === '87f14d0f-7d95-4a76-b2db-bf0f1ca6f3a1'
                        && $context['mcp_tool_name'] === 'demo_tool'
                        && $context['mcp_params_mode'] === 'redacted'
                        && is_int($context['mcp_response_bytes'])
                        && $context['mcp_response_bytes'] > 0;
                })
            );

        $handler = $this->createObservedHandler($registry, $logger, false);
        $response = $handler->handle($request, $session);

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);
    }

    public function testUnknownToolReturnsMethodNotFoundAndLogsFailure(): void
    {
        $registry = new Registry();
        $logger = $this->createMock(LoggerInterface::class);
        $session = $this->createSession('e6b0fd2f-24a8-4a74-bf74-ec56d99963dd');
        $request = (new CallToolRequest('missing_tool', ['query' => 'secret']))->withId('r-2');

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'MCP Tool Call failed: Tool not found: "missing_tool". [query: <string:6>]'
                . ' [session=e6b0fd2f-24a8-4a74-bf74-ec56d99963dd]',
                self::callback(static fn(array $context): bool => ($context['mcp_params_mode'] ?? null) === 'redacted'
                    && ($context['mcp_session_id'] ?? null) === 'e6b0fd2f-24a8-4a74-bf74-ec56d99963dd')
            );

        $handler = $this->createObservedHandler($registry, $logger, false);
        $error = $handler->handle($request, $session);

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::METHOD_NOT_FOUND, $error->code);
    }

    public function testValidationFailureReturnsInvalidParamsAndKeepsRedactedLogging(): void
    {
        $registry = new Registry();
        $logger = $this->createMock(LoggerInterface::class);
        $session = $this->createSession('c552b0bc-8d0c-4e3f-bf2a-e6d6d42d1ecf');
        $request = (new CallToolRequest('demo_tool', ['query' => 'secret']))->withId('r-3');

        $registry->registerTool($this->createTool('demo_tool', [
            'type' => 'object',
            'properties' => ['id' => ['type' => 'integer']],
            'required' => ['id'],
        ]), static fn(int $id): array => ['id' => $id]);

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                self::callback(static function (string $message): bool {
                    return str_contains($message, 'MCP Tool Call failed: Invalid parameters for tool')
                        && str_contains($message, '[query: <string:6>]')
                        && !str_contains($message, 'secret');
                }),
                self::callback(static fn(mixed $context): bool => is_array($context))
            );

        $handler = $this->createObservedHandler($registry, $logger, false);
        $error = $handler->handle($request, $session);

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::INVALID_PARAMS, $error->code);
    }

    public function testToolCallExceptionReturnsToolErrorResponseAndLogsFailure(): void
    {
        $registry = new Registry();
        $logger = $this->createMock(LoggerInterface::class);
        $session = $this->createSession('9d7bbcf8-c0c4-4379-87be-ae04689f80e1');
        $request = (new CallToolRequest('demo_tool', ['id' => 1]))->withId('r-4');

        $registry->registerTool(
            $this->createTool('demo_tool', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => [],
            ]),
            static function (): void {
                throw new ToolCallException('Tool execution failed');
            }
        );

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'MCP Tool Call failed: Tool execution failed [id: <int>]'
                . ' [session=9d7bbcf8-c0c4-4379-87be-ae04689f80e1]',
                self::callback(static fn(mixed $context): bool => is_array($context)
                    && ($context['mcp_session_id'] ?? null) === '9d7bbcf8-c0c4-4379-87be-ae04689f80e1')
            );

        $handler = $this->createObservedHandler($registry, $logger, false);
        $response = $handler->handle($request, $session);

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertTrue($response->result->isError);
    }

    public function testUnexpectedThrowableReturnsInternalErrorAndLogsFailure(): void
    {
        $registry = new Registry();
        $logger = $this->createMock(LoggerInterface::class);
        $session = $this->createSession('f47ac10b-58cc-4372-a567-0e02b2c3d479');
        $request = (new CallToolRequest('demo_tool', ['id' => 1]))->withId('r-5');

        $registry->registerTool(
            $this->createTool('demo_tool', [
                'type' => 'object',
                'properties' => ['id' => ['type' => 'integer']],
                'required' => [],
            ]),
            static function (): void {
                throw new \RuntimeException('boom');
            }
        );

        $logger->expects(self::once())
            ->method('debug')
            ->with(
                'MCP Tool Call failed: Error while executing tool [id: <int>]'
                . ' [session=f47ac10b-58cc-4372-a567-0e02b2c3d479]',
                self::callback(static fn(mixed $context): bool => is_array($context)
                    && ($context['mcp_session_id'] ?? null) === 'f47ac10b-58cc-4372-a567-0e02b2c3d479')
            );

        $handler = $this->createObservedHandler($registry, $logger, false);
        $error = $handler->handle($request, $session);

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::INTERNAL_ERROR, $error->code);
    }

    public function testWrapperMatchesSdkPayloadForSuccessPath(): void
    {
        $session = $this->createSession('df3ecd9b-9f75-4203-abca-9d796ebbb1ab');
        $request = (new CallToolRequest('demo_tool', ['id' => 4, 'query' => 'abc']))->withId('parity-success');

        $registryForObserved = new Registry();
        $registryForSdk = new Registry();

        $tool = $this->createTool('demo_tool', [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'query' => ['type' => 'string'],
            ],
            'required' => [],
        ]);

        $callback = static fn(int $id, string $query): array => ['ok' => $id === 4 && $query === 'abc'];
        $registryForObserved->registerTool($tool, $callback);
        $registryForSdk->registerTool($tool, $callback);

        $observed = $this->createObservedHandler($registryForObserved, $this->createMock(LoggerInterface::class), false)
            ->handle($request, $session);
        $sdk = $this->createSdkHandler($registryForSdk)->handle($request, $session);

        self::assertSame($this->encodeMessage($sdk), $this->encodeMessage($observed));
    }

    public function testWrapperMatchesSdkPayloadForFailurePaths(): void
    {
        $session = $this->createSession('5f265ef3-bf92-499f-a7a7-f8ca170de6a2');

        $cases = [
            [
                'request' => (new CallToolRequest('missing_tool', ['id' => 1]))->withId('parity-missing'),
                'tool' => null,
                'callback' => null,
            ],
            [
                'request' => (new CallToolRequest('demo_tool', ['query' => 'abc']))->withId('parity-invalid'),
                'tool' => $this->createTool('demo_tool', [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => ['id'],
                ]),
                'callback' => static fn(int $id): array => ['id' => $id],
            ],
            [
                'request' => (new CallToolRequest('demo_tool', ['id' => 1]))->withId('parity-tool-error'),
                'tool' => $this->createTool('demo_tool', [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => [],
                ]),
                'callback' => static function (): void {
                    throw new ToolCallException('Tool execution failed');
                },
            ],
            [
                'request' => (new CallToolRequest('demo_tool', ['id' => 1]))->withId('parity-internal-error'),
                'tool' => $this->createTool('demo_tool', [
                    'type' => 'object',
                    'properties' => ['id' => ['type' => 'integer']],
                    'required' => [],
                ]),
                'callback' => static function (): void {
                    throw new \RuntimeException('boom');
                },
            ],
        ];

        foreach ($cases as $case) {
            $registryForObserved = new Registry();
            $registryForSdk = new Registry();

            if (isset($case['tool'], $case['callback'])) {
                $registryForObserved->registerTool($case['tool'], $case['callback']);
                $registryForSdk->registerTool($case['tool'], $case['callback']);
            }

            $observed = $this->createObservedHandler(
                $registryForObserved,
                $this->createMock(LoggerInterface::class),
                false
            )->handle($case['request'], $session);
            $sdk = $this->createSdkHandler($registryForSdk)
                ->handle($case['request'], $session);

            self::assertSame($this->encodeMessage($sdk), $this->encodeMessage($observed));
        }
    }

    /**
     * @param array{
     *     type: 'object',
     *     properties: array<string, mixed>,
     *     required: array<string>|null
     * } $inputSchema
     */
    private function createTool(string $name, array $inputSchema): \Matomo\Dependencies\McpServer\Mcp\Schema\Tool
    {
        return new \Matomo\Dependencies\McpServer\Mcp\Schema\Tool($name, $inputSchema, null, null);
    }

    private function createSession(string $uuid): Session
    {
        return new Session(new InMemorySessionStore(), Uuid::fromString($uuid));
    }

    private function createObservedHandler(
        Registry $registry,
        LoggerInterface $logger,
        bool $fullParameterLoggingEnabled
    ): ObservedCallToolHandler {
        return new ObservedCallToolHandler(
            $this->createSdkHandler($registry),
            $logger,
            new ToolCallParameterFormatter(),
            $fullParameterLoggingEnabled
        );
    }

    private function createSdkHandler(Registry $registry): CallToolHandler
    {
        return new CallToolHandler($registry, new ReferenceHandler(), new NullLogger());
    }

    /**
     * @param Response<CallToolResult>|Error $message
     */
    private function encodeMessage(Response|Error $message): string
    {
        $encoded = json_encode(
            $message->jsonSerialize(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return is_string($encoded) ? $encoded : '';
    }
}
