<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Server\Handler\Request;

use Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Piwik\Log\LoggerInterface;
use Piwik\Plugins\McpServer\Support\Logging\McpToolCallOrigin;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;

/**
 * @implements RequestHandlerInterface<mixed>
 */
final class ObservedCallToolHandler implements RequestHandlerInterface
{
    /**
     * @param RequestHandlerInterface<mixed> $delegate
     */
    public function __construct(
        private readonly RequestHandlerInterface $delegate,
        private readonly LoggerInterface $logger,
        private readonly ToolCallParameterFormatter $parameterFormatter,
        private readonly bool $fullParameterLoggingEnabled,
        private readonly string $logLevel,
    ) {
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /**
     * @param CallToolRequest $request
     *
     * @return Response<mixed>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        $toolName = $request->name;
        $rawArguments = $request->arguments;
        $loggingMode = $this->fullParameterLoggingEnabled ? 'full' : 'redacted';

        $baseContext = [
            'mcp_session_id' => $this->resolveSessionId($session),
            'mcp_tool_name' => $toolName,
            'mcp_params_mode' => $loggingMode,
            McpToolCallOrigin::LOG_CONTEXT_KEY => $this->resolveCallOrigin($session),
        ];

        $result = $this->delegate->handle($request, $session);
        if ($result instanceof Response && $result->result instanceof CallToolResult && !$result->result->isError) {
            $responseSize = $this->estimateResponseBytes($result);
            $successContext = $baseContext;
            $successContext['mcp_response_bytes'] = $responseSize;
            $this->logSuccess($toolName, $rawArguments, $loggingMode, $successContext);
        } else {
            $this->logFailure(
                $this->extractFailureMessage($result),
                $rawArguments,
                $loggingMode,
                $baseContext,
            );
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function logSuccess(string $toolName, array $arguments, string $mode, array $context): void
    {
        $formattedArguments = $this->parameterFormatter->format($arguments, $mode === 'full');
        $sessionId = $this->resolveContextSessionId($context);
        $responseBytes = $context['mcp_response_bytes'] ?? null;
        $hasResponseBytes = is_int($responseBytes);

        $message = 'MCP Tool Call successful: {tool_name} [{formatted_arguments}]';

        if ($hasResponseBytes) {
            $message .= ' [session={session_id}, response_bytes={response_bytes}]';
        } else {
            $message .= ' [session={session_id}]';
        }

        $loggingContext = $context;
        $loggingContext['tool_name'] = $toolName;
        $loggingContext['formatted_arguments'] = $formattedArguments;
        $loggingContext['session_id'] = $sessionId;

        if ($hasResponseBytes) {
            $loggingContext['response_bytes'] = $responseBytes;
        }

        $this->logAtConfiguredLevel($message, $loggingContext);
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $context
     */
    private function logFailure(string $errorMessage, array $arguments, string $mode, array $context): void
    {
        $formattedArguments = $this->parameterFormatter->format($arguments, $mode === 'full');
        $message = 'MCP Tool Call failed: {error_message} [{formatted_arguments}] [session={session_id}]';
        $loggingContext = $context;
        $loggingContext['error_message'] = $errorMessage;
        $loggingContext['formatted_arguments'] = $formattedArguments;
        $loggingContext['session_id'] = $this->resolveContextSessionId($context);

        $this->logAtConfiguredLevel($message, $loggingContext);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function resolveContextSessionId(array $context): string
    {
        $sessionId = $context['mcp_session_id'] ?? 'unknown';
        return is_scalar($sessionId) ? (string) $sessionId : 'unknown';
    }

    private function resolveSessionId(SessionInterface $session): string
    {
        try {
            return $session->getId()->toRfc4122();
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    private function resolveCallOrigin(SessionInterface $session): string
    {
        try {
            $origin = $session->get(McpToolCallOrigin::SESSION_KEY, McpToolCallOrigin::ORIGIN_HTTP);
        } catch (\Throwable) {
            return McpToolCallOrigin::ORIGIN_HTTP;
        }

        if ($origin === McpToolCallOrigin::ORIGIN_INTERNAL) {
            return McpToolCallOrigin::ORIGIN_INTERNAL;
        }

        return McpToolCallOrigin::ORIGIN_HTTP;
    }

    /**
     * @param Response<mixed> $response
     */
    private function estimateResponseBytes(Response $response): ?int
    {
        $encoded = json_encode(
            $response->jsonSerialize(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        if (!is_string($encoded)) {
            return null;
        }

        return strlen($encoded);
    }

    /**
     * @param Response<mixed>|Error $result
     */
    private function extractFailureMessage(Response|Error $result): string
    {
        if ($result instanceof Error) {
            return $result->message;
        }

        $callToolResult = $result->result;
        if (!$callToolResult instanceof CallToolResult || !$callToolResult->isError) {
            return 'Tool execution failed';
        }

        foreach ($callToolResult->content as $contentItem) {
            if ($contentItem instanceof TextContent && is_scalar($contentItem->text)) {
                return (string) $contentItem->text;
            }
        }

        return 'Tool execution failed';
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAtConfiguredLevel(string $message, array $context): void
    {
        switch ($this->logLevel) {
            case 'ERROR':
                $this->logger->error($message, $context);
                return;
            case 'WARN':
            case 'WARNING':
                $this->logger->warning($message, $context);
                return;
            case 'INFO':
                $this->logger->info($message, $context);
                return;
            case 'VERBOSE':
            case 'DEBUG':
            default:
                $this->logger->debug($message, $context);
                return;
        }
    }
}
