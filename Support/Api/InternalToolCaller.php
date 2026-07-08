<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\Session;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Contracts\Events\McpToolCallEvent;
use Piwik\Plugins\McpServer\Server\InternalAccess;
use Piwik\Plugins\McpServer\Server\ServerEventBridge;
use Piwik\Plugins\McpServer\Support\Logging\McpToolCallOrigin;
use Piwik\SettingsPiwik;
use Piwik\Version;

/**
 * Invoke a single MCP tool via the same handler chain used by the public
 * HTTP endpoint, returning a flat array shape free of SDK types so the
 * Matomo API layer can return it to other plugins safely.
 */
final class InternalToolCaller
{
    private const INTERNAL_REQUEST_ID = 'matomo-internal';
    private const INTERNAL_SESSION_PREFIX = 'matomo-internal-';
    private const INTERNAL_TRANSPORT = 'internal';
    private const INTERNAL_CLIENT_NAME = 'Matomo';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array{
     *     content: list<array<string, mixed>>,
     *     structuredContent: array<string, mixed>|null,
     *     isError: bool
     * }
     */
    public function call(InternalAccess $access, string $name, array $arguments, ?string $sessionKey = null): array
    {
        $request = (new CallToolRequest($name, $arguments))->withId(self::INTERNAL_REQUEST_ID);
        $sessionId = $this->internalSessionId($sessionKey);
        $startedAt = microtime(true);

        try {
            $response = $access->callToolHandler->handle($request, $this->createSession());
        } catch (\Throwable $e) {
            $this->publishToolCallEvent($sessionId, $name, true, $startedAt, JsonRpcError::INTERNAL_ERROR);
            throw $e;
        }

        if ($response instanceof JsonRpcError) {
            $this->publishToolCallEvent($sessionId, $name, true, $startedAt, $response->code);
            return $this->errorPayload($response->message);
        }

        $result = $response->result;
        if (!$result instanceof CallToolResult) {
            $this->publishToolCallEvent($sessionId, $name, true, $startedAt, JsonRpcError::INTERNAL_ERROR);
            return $this->errorPayload('MCP call handler returned an unsupported result type.');
        }

        $content = $this->serializeContent($result->content);
        if ($content === null) {
            $this->publishToolCallEvent($sessionId, $name, true, $startedAt, JsonRpcError::INTERNAL_ERROR);
            return $this->errorPayload('MCP call returned content that could not be serialised.');
        }

        $this->publishToolCallEvent($sessionId, $name, $result->isError, $startedAt);

        return [
            'content' => $content,
            'structuredContent' => $result->structuredContent,
            'isError' => $result->isError,
        ];
    }

    /**
     * @param array<int, mixed> $blocks
     * @return list<array<string, mixed>>|null null when an SDK content block flattens to a non-object value
     */
    private function serializeContent(array $blocks): ?array
    {
        // Recursive flatten rather than a json_encode/json_decode round-trip:
        // the round-trip would silently collapse any `new \stdClass()` placeholder
        // inside a content block (e.g. an empty `_meta` object that a tool wants
        // to emit as JSON `{}`) into `[]`, which the consumer would then re-encode
        // as `[]` for the LLM — spec-invalid. Walking JsonSerializable / array /
        // stdClass explicitly preserves the JSON object/array distinction.
        $normalized = [];
        foreach ($blocks as $block) {
            $flat = $this->flatten($block);
            if (!is_array($flat)) {
                // SDK Content always serialises to a JSON object; bail out rather
                // than silently dropping unexpected entries so the caller sees an
                // isError payload instead of a truncated list.
                return null;
            }
            $normalized[] = $flat;
        }

        return $normalized;
    }

    /**
     * Recursively unwrap JsonSerializable values while preserving the JSON
     * object/array distinction: stdClass stays stdClass (round-trips as `{}`),
     * associative SDK output stays array (round-trips as `[]` or object,
     * controlled by the SDK).
     */
    private function flatten(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return $this->flatten($value->jsonSerialize());
        }

        if ($value instanceof \stdClass) {
            $out = new \stdClass();
            foreach ((array) $value as $key => $nested) {
                $out->{$key} = $this->flatten($nested);
            }
            return $out;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $nested) {
                $out[$key] = $this->flatten($nested);
            }
            return $out;
        }

        return $value;
    }

    /**
     * @return array{
     *     content: list<array<string, mixed>>,
     *     structuredContent: array<string, mixed>|null,
     *     isError: bool
     * }
     */
    private function errorPayload(string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'structuredContent' => null,
            'isError' => true,
        ];
    }

    private function internalSessionId(?string $sessionKey): string
    {
        $normalizedKey = is_string($sessionKey) ? trim($sessionKey) : '';
        if ($normalizedKey !== '') {
            return self::INTERNAL_SESSION_PREFIX . hash('sha256', SettingsPiwik::getSalt() . "\0" . $normalizedKey);
        }

        return self::INTERNAL_SESSION_PREFIX . bin2hex(random_bytes(16));
    }

    private function publishToolCallEvent(
        string $sessionId,
        string $toolName,
        bool $isError,
        float $startedAt,
        ?int $errorCode = null,
    ): void {
        try {
            ServerEventBridge::publishEvent(new McpToolCallEvent(
                $sessionId,
                $toolName,
                $isError,
                (int) round((microtime(true) - $startedAt) * 1000),
                $errorCode,
                self::INTERNAL_TRANSPORT,
                self::INTERNAL_CLIENT_NAME,
                Version::VERSION,
            ));
        } catch (\Throwable $e) {
            // Observability must never disrupt an internal MCP bridge response.
            $this->logger->debug('McpServer internal tool-call event was not published: ' . $e->getMessage());
        }
    }

    /**
     * Build a fresh per-call session bound to an in-memory store and tagged so
     * ObservedCallToolHandler can stamp the log line with
     * mcp_call_origin=internal.
     *
     * Per-call rather than memoised: this service is registered as a singleton
     * in Matomo's container, which means its lifetime matches the PHP process
     * — fine under PHP-FPM where process == request, but in long-running CLI
     * workers a cached session would bleed across logical operations. The MCP
     * Session is a protocol-layer artifact; cross-call log correlation for
     * in-process callers can ride on Matomo's existing request/user context
     * instead. The InMemorySessionStore is heap-only, so even a future tool
     * that calls Session::save() cannot reach the configured DbSessionStore.
     */
    private function createSession(): Session
    {
        $session = new Session(new InMemorySessionStore());
        $session->set(McpToolCallOrigin::SESSION_KEY, McpToolCallOrigin::ORIGIN_INTERNAL);

        return $session;
    }
}
