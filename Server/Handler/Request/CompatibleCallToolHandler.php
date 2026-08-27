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
use Piwik\Plugins\McpServer\Support\Normalization\ArgumentIssueException;
use Piwik\Plugins\McpServer\Support\Normalization\IntakeNormalizer;
use Piwik\Plugins\McpServer\Support\Normalization\ToolIntakeProfile;
use Piwik\Plugins\McpServer\Support\Normalization\ToolIntakeProfiles;

/**
 * Adaptation of the SDK's {@see \Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\CallToolHandler}.
 *
 * The flow mirrors the SDK handler (look up the tool, validate arguments against the
 * input schema, invoke the reference handler, wrap the result). It diverges only where
 * the SDK's strict validation rejects input that our tools can handle, plus one exception
 * type swap:
 *
 * - intake normalization: arguments of tools registered in {@see ToolIntakeProfiles} are reduced
 *   to one canonical form, so equivalent selectors and known key aliases converge. It runs ahead
 *   of validation and the canonical result is what gets validated and dispatched, so a recovery
 *   cannot skip a schema constraint. A recovery is silent; only a contradiction is reported.
 * - normalizeArgumentsForValidation(): an empty parameter object cannot arrive here as one. The
 *   SDK decodes the request body with `json_decode(assoc: true)`, which maps both `{}` and `[]`
 *   to PHP `[]`, and its `SchemaValidator` promotes only non-empty associative arrays back to
 *   `stdClass`, so either spelling reaches an `object`-typed field as an array and fails. The
 *   substitution is therefore what lets a spec-correct client send `{}`, not only a tolerance for
 *   the LLM clients that emit `[]`. The fields that accept it come from the tool's intake profile,
 *   so {@see ToolIntakeProfiles} stays the one place declaring what a tool recovers. A tool with
 *   no profile gets no substitution.
 * - coerceIntegerStringsForValidation(): promotes pure integer strings (e.g. "1") to
 *   integers for `integer`-typed arguments, which smaller LLMs frequently stringify. This covers
 *   every tool, profiled or not, and is the only place the promotion happens; intake profiles
 *   register no retyping of their own.
 * - It catches this plugin's {@see McpToolCallException} rather than the SDK's
 *   ToolCallException.
 *
 * Argument problems leave through two channels: schema validation keeps the SDK handler's JSON-RPC
 * `Error`, so a client relying on `-32602` for invalid params keeps seeing it, while an
 * {@see ArgumentIssueException} from normalization returns an `isError` result, which clients
 * surface to the model that can act on it.
 *
 * Logging is intentionally omitted here: the SDK handler's logger plumbing is dropped and
 * tool-call logging lives in the {@see ObservedCallToolHandler} decorator instead.
 *
 * @implements RequestHandlerInterface<mixed>
 */
final class CompatibleCallToolHandler implements RequestHandlerInterface
{
    private SchemaValidator $schemaValidator;

    /**
     * @param array<string, class-string> $toolClasses the class registered under each tool name,
     *                                                 so {@see ToolIntakeProfiles} can key on the
     *                                                 class rather than the name
     */
    public function __construct(
        private readonly RegistryInterface $registry,
        private readonly ReferenceHandlerInterface $referenceHandler,
        ?SchemaValidator $schemaValidator = null,
        private readonly array $toolClasses = [],
    ) {
        $this->schemaValidator = $schemaValidator ?? new SchemaValidator();
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

        try {
            $reference = $this->registry->getTool($toolName);
        } catch (ToolNotFoundException $e) {
            return new Error($request->getId(), Error::METHOD_NOT_FOUND, $e->getMessage());
        }

        $profile = ToolIntakeProfiles::forToolClass($this->toolClasses[$toolName] ?? null);
        if ($profile !== null) {
            try {
                $rawArguments = (new IntakeNormalizer($profile))->normalize($rawArguments);
            } catch (ArgumentIssueException $e) {
                return new Response(
                    $request->getId(),
                    CallToolResult::error([new TextContent($e->getMessage())]),
                );
            }
        }

        $validationArguments = $this->normalizeArgumentsForValidation($profile, $rawArguments);
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

            return new Response($request->getId(), $result);
        } catch (McpToolCallException $e) {
            return new Response($request->getId(), CallToolResult::error([new TextContent($e->getMessage())]));
        } catch (\Throwable) {
            return Error::forInternalError('Error while executing tool', $request->getId());
        }
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function normalizeArgumentsForValidation(?ToolIntakeProfile $profile, array $arguments): array
    {
        if ($profile === null) {
            return $arguments;
        }

        foreach ($profile->objectFields as $field) {
            if (($arguments[$field] ?? null) !== []) {
                continue;
            }

            $arguments[$field] = new \stdClass();
        }

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
