<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Server\Handler\Request;

use Matomo\Dependencies\McpServer\Mcp\Capability\Discovery\SchemaValidator;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ReferenceHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Capability\RegistryInterface;
use Matomo\Dependencies\McpServer\Mcp\Exception\ToolNotFoundException;
use Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * @implements RequestHandlerInterface<mixed>
 */
final class CompatibleCallToolHandler implements RequestHandlerInterface
{
    /** @var array<string, true> */
    private const EMPTY_LIST_COMPATIBILITY_TOOLS = [
        ReportMetadata::TOOL_NAME => true,
        ReportProcessed::TOOL_NAME => true,
    ];

    private SchemaValidator $schemaValidator;

    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly ReferenceHandlerInterface $referenceHandler,
        private readonly LoggerInterface $logger = new NullLogger(),
        ?SchemaValidator $schemaValidator = null,
    ) {
        $this->schemaValidator = $schemaValidator ?? new SchemaValidator($logger);
    }

    public function supports(Request $request): bool
    {
        return $request instanceof CallToolRequest;
    }

    /**
     * @return Response<CallToolResult>|Error
     */
    public function handle(Request $request, SessionInterface $session): Response|Error
    {
        \assert($request instanceof CallToolRequest);

        $toolName = $request->name;
        $rawArguments = $request->arguments ?? [];

        $this->logger->debug('Executing tool', ['name' => $toolName, 'arguments' => $rawArguments]);

        try {
            $reference = $this->registry->getTool($toolName);
        } catch (ToolNotFoundException $e) {
            $this->logger->error('Tool not found', ['name' => $toolName]);

            return new Error($request->getId(), Error::METHOD_NOT_FOUND, $e->getMessage());
        }

        $validationArguments = $this->normalizeArgumentsForValidation($toolName, $rawArguments);
        $validationArguments = $this->coerceIntegerStringsForValidation(
            $validationArguments,
            $reference->tool->inputSchema,
        );

        $validationErrors = $this->schemaValidator->validateAgainstJsonSchema(
            $validationArguments,
            $reference->tool->inputSchema,
        );
        if (!empty($validationErrors)) {
            $errorMessages = [];
            foreach ($validationErrors as $errorDetail) {
                $pointer = $errorDetail['pointer'];
                $message = $errorDetail['message'];
                $errorMessages[] = ($pointer !== '/' && $pointer !== '' ? "Property '{$pointer}': " : '') . $message;
            }

            $summaryMessage = "Invalid parameters for tool '{$toolName}': "
                . implode('; ', array_slice($errorMessages, 0, 3));
            if (count($errorMessages) > 3) {
                $summaryMessage .= '; ...and more errors.';
            }

            return Error::forInvalidParams(
                $summaryMessage,
                $request->getId(),
                ['validation_errors' => $validationErrors],
            );
        }

        $rawArguments['_session'] = $session;
        $rawArguments['_request'] = $request;

        try {
            $result = $this->referenceHandler->handle($reference, $rawArguments);
            $structuredContent = null;
            if (!$result instanceof CallToolResult) {
                $structuredContent = $reference->extractStructuredContent($result);
                $result = new CallToolResult($reference->formatResult($result), structuredContent: $structuredContent);
            }

            $this->logger->debug('Tool executed successfully', [
                'name' => $toolName,
                'result_type' => gettype($result),
                'structured_content' => $structuredContent,
            ]);

            return new Response($request->getId(), $result);
        } catch (McpToolCallException $e) {
            $this->logger->error(
                sprintf('Error while executing tool "%s": "%s".', $toolName, $e->getMessage()),
                ['tool' => $toolName, 'arguments' => $rawArguments],
            );

            return new Response($request->getId(), CallToolResult::error([new TextContent($e->getMessage())]));
        } catch (\Throwable $e) {
            $this->logger->error('Unhandled error during tool execution', ['name' => $toolName, 'exception' => $e]);

            return Error::forInternalError('Error while executing tool', $request->getId());
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function normalizeArgumentsForValidation(string $toolName, array $arguments): array
    {
        if (
            !isset(self::EMPTY_LIST_COMPATIBILITY_TOOLS[$toolName])
            || !array_key_exists('apiParameters', $arguments)
            || $arguments['apiParameters'] !== []
        ) {
            return $arguments;
        }

        $arguments['apiParameters'] = new \stdClass();

        return $arguments;
    }

    /**
     * Losslessly promotes pure integer strings (e.g. "1") to integers for any top-level
     * argument whose schema requires a bare `integer`. Clients - notably smaller LLMs -
     * routinely emit stringified numbers; without this the strict `integer` check rejects
     * them even though the handler would cast them fine on its own.
     *
     * Scope is deliberately limited to properties whose sole declared type is `integer`.
     * Union parameters (e.g. `oneOf: [integer, string]`) already accept the string form at
     * validation and the handler passes unions through untouched, so coercing them would be
     * a no-op. Values that would change under the round-trip ("1.5", "01", "1e3", integer
     * overflow) or are non-numeric are left untouched so they still surface the normal
     * validation error.
     *
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $inputSchema
     *
     * @return array<string, mixed>
     */
    private function coerceIntegerStringsForValidation(array $arguments, array $inputSchema): array
    {
        $properties = $inputSchema['properties'] ?? null;
        if (!is_array($properties)) {
            return $arguments;
        }

        foreach ($arguments as $name => $value) {
            if (!is_string($value) || $value === '') {
                continue;
            }

            $property = $properties[$name] ?? null;
            if (!is_array($property) || ($property['type'] ?? null) !== 'integer') {
                continue;
            }

            if ((string) (int) $value === $value) {
                $arguments[$name] = (int) $value;
            }
        }

        return $arguments;
    }
}
