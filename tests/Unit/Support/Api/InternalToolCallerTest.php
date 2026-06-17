<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Api;

use Matomo\Dependencies\McpServer\Mcp\Capability\RegistryInterface;
use Matomo\Dependencies\McpServer\Mcp\Schema\Content\Content;
use Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\EmptyResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Server\InternalAccess;
use Piwik\Plugins\McpServer\Support\Api\InternalToolCaller;
use Piwik\Plugins\McpServer\Support\Logging\McpToolCallOrigin;

/**
 * @group McpServer
 * @group Plugins
 */
class InternalToolCallerTest extends TestCase
{
    public function testCallReturnsFlatPayloadOnSuccessfulToolResult(): void
    {
        $handler = $this->stubHandler(new CallToolResult([new TextContent('hello world')], false));

        $result = (new InternalToolCaller())->call($this->access($handler), 'matomo_demo', ['k' => 'v']);

        self::assertFalse($result['isError']);
        self::assertNull($result['structuredContent']);
        self::assertCount(1, $result['content']);
        $block = $result['content'][0];
        self::assertSame('text', $block['type']);
        self::assertSame('hello world', $block['text']);
    }

    public function testCallSurfacesStructuredContentWhenToolEmitsIt(): void
    {
        $structured = ['sites' => [['idsite' => 1, 'name' => 'Demo']], 'limit' => 10];
        $handler = $this->stubHandler(new CallToolResult(
            [new TextContent(json_encode($structured) ?: '')],
            false,
            $structured,
        ));

        $result = (new InternalToolCaller())->call($this->access($handler), 'matomo_demo', []);

        self::assertFalse($result['isError']);
        self::assertSame($structured, $result['structuredContent']);
    }

    public function testCallPreservesStdClassInStructuredContent(): void
    {
        // A tool that emits a structuredContent fragment containing
        // `new \stdClass()` (the canonical way to force JSON `{}`) must
        // reach the consumer with that stdClass intact; otherwise a consumer
        // re-encoding the structured payload for an external MCP client
        // would emit `[]` and break a schema that declared the field as
        // an empty JSON object.
        $metaPlaceholder = new \stdClass();
        $structured = ['result' => 'ok', '_meta' => $metaPlaceholder];
        $handler = $this->stubHandler(new CallToolResult(
            [new TextContent('ok')],
            false,
            $structured,
        ));

        $result = (new InternalToolCaller())->call($this->access($handler), 'any', []);

        self::assertIsArray($result['structuredContent']);
        self::assertSame($metaPlaceholder, $result['structuredContent']['_meta']);
        $encoded = json_encode($result['structuredContent']);
        self::assertIsString($encoded);
        self::assertStringContainsString('"_meta":{}', $encoded);
    }

    public function testCallReturnsErrorPayloadWhenHandlerReturnsJsonRpcError(): void
    {
        $handler = $this->stubHandler(new JsonRpcError('matomo-internal', -32601, 'Tool not found'));

        $result = (new InternalToolCaller())->call($this->access($handler), 'missing', []);

        self::assertTrue($result['isError']);
        self::assertNull($result['structuredContent']);
        self::assertSame([['type' => 'text', 'text' => 'Tool not found']], $result['content']);
    }

    public function testCallReturnsErrorPayloadWhenHandlerReturnsUnsupportedResultType(): void
    {
        // EmptyResult implements ResultInterface but is not a CallToolResult, so
        // the caller must reject it with the unsupported-result-type message
        // rather than returning a half-built payload.
        $handler = $this->stubHandler(new EmptyResult());

        $result = (new InternalToolCaller())->call($this->access($handler), 'any', []);

        self::assertTrue($result['isError']);
        self::assertNull($result['structuredContent']);
        self::assertSame(
            [['type' => 'text', 'text' => 'MCP call handler returned an unsupported result type.']],
            $result['content'],
        );
    }

    public function testCallPreservesStdClassInContentBlock(): void
    {
        // Content blocks themselves rarely carry empty objects today, but the
        // recursive flattener replaces the previous json_encode/json_decode
        // round-trip precisely so a future block that legitimately emits a
        // nested `{}` (e.g. an empty `_meta` field) is not silently flattened
        // to `[]` on its way to the consumer.
        $metaPlaceholder = new \stdClass();
        $block = new class ($metaPlaceholder) extends Content {
            public function __construct(private \stdClass $meta)
            {
                parent::__construct('text');
            }

            /**
             * @return array<string, mixed>
             */
            public function jsonSerialize(): array
            {
                return ['type' => 'text', 'text' => 'ok', '_meta' => $this->meta];
            }
        };
        $handler = $this->stubHandler(new CallToolResult([$block], false));

        $result = (new InternalToolCaller())->call($this->access($handler), 'any', []);

        self::assertFalse($result['isError']);
        self::assertCount(1, $result['content']);
        // The flattener clones stdClass into a fresh instance so it can also
        // unwrap any nested JsonSerializable values; identity is not preserved
        // but the JSON shape (an object that re-encodes as `{}`) is.
        self::assertInstanceOf(\stdClass::class, $result['content'][0]['_meta']);
        $encoded = json_encode($result['content'][0]);
        self::assertIsString($encoded);
        self::assertStringContainsString('"_meta":{}', $encoded);
    }

    public function testCallReturnsErrorPayloadWhenContentBlockSerialisesToNonObject(): void
    {
        // SDK Content always serialises to a JSON object; a block whose
        // jsonSerialize() returns a non-array value must be reported as
        // an isError payload rather than silently truncated.
        $block = new class () extends Content {
            public function __construct()
            {
                parent::__construct('text');
            }

            public function jsonSerialize(): string
            {
                return 'definitely-not-an-object';
            }
        };
        $handler = $this->stubHandler(new CallToolResult([$block], false));

        $result = (new InternalToolCaller())->call($this->access($handler), 'any', []);

        self::assertTrue($result['isError']);
        self::assertNull($result['structuredContent']);
        self::assertSame(
            [['type' => 'text', 'text' => 'MCP call returned content that could not be serialised.']],
            $result['content'],
        );
    }

    public function testEachCallUsesAFreshSessionAndStampsInternalOrigin(): void
    {
        // The caller is registered as a singleton, so its lifetime spans the
        // whole PHP process. Building a new Session per call keeps long-running
        // CLI workers from bleeding state across logically distinct operations
        // — the only thing repeated calls need to share is the origin marker,
        // which is constant.
        $observedSessions = [];
        $observedOrigins = [];

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            function ($request, SessionInterface $session) use (&$observedSessions, &$observedOrigins): Response {
                $observedSessions[] = $session->getId()->toRfc4122();
                $observedOrigins[] = $session->get(McpToolCallOrigin::SESSION_KEY);

                /** @var Response<mixed> $response */
                $response = new Response('matomo-internal', new CallToolResult([new TextContent('ok')], false));

                return $response;
            },
        );

        $caller = new InternalToolCaller();
        $caller->call($this->access($handler), 'matomo_demo', []);
        $caller->call($this->access($handler), 'matomo_demo', []);

        self::assertCount(2, $observedSessions);
        self::assertNotSame($observedSessions[0], $observedSessions[1]);
        self::assertSame(
            [McpToolCallOrigin::ORIGIN_INTERNAL, McpToolCallOrigin::ORIGIN_INTERNAL],
            $observedOrigins,
        );
    }

    /**
     * Build a {@see RequestHandlerInterface} stub whose handle() always returns
     * either the given {@see JsonRpcError} or a {@see Response} wrapping the
     * given SDK result. Routed through PHPUnit's mock builder so we don't have
     * to ship per-test fixture classes for the few static responses we need.
     *
     * @return RequestHandlerInterface<mixed>
     */
    private function stubHandler(\JsonSerializable|JsonRpcError $payload): RequestHandlerInterface
    {
        if ($payload instanceof JsonRpcError) {
            $return = $payload;
        } else {
            /** @var Response<mixed> $return */
            $return = new Response('matomo-internal', $payload);
        }

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($return);

        return $handler;
    }

    /**
     * @param RequestHandlerInterface<mixed> $handler
     */
    private function access(RequestHandlerInterface $handler): InternalAccess
    {
        $registry = $this->createMock(RegistryInterface::class);

        return new InternalAccess($registry, $handler);
    }
}
