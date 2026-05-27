<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Matomo\Dependencies\McpServer\Opis\JsonSchema\Errors\ValidationError;
use Matomo\Dependencies\McpServer\Opis\JsonSchema\Validator;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpToolSchemaMetaschema;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 *
 * @phpstan-type ConformanceError array{pointer: string, keyword: string, message: string}
 * @phpstan-type ToolEntry object{name: string, inputSchema: object, outputSchema?: object}
 */
class McpToolSchemaConformanceTest extends IntegrationTestCase
{
    private string $originalRawApiAccessMode = '';

    protected static function configureFixture($fixture): void
    {
        parent::configureFixture($fixture);

        $fixture->createSuperUser = true;
    }

    public function setUp(): void
    {
        parent::setUp();

        $this->originalRawApiAccessMode = McpTestHelper::getRawApiAccessMode();
        // Maximize tool registration so every conditionally-registered raw
        // API call tool is included in the conformance check.
        McpTestHelper::setRawApiAccessMode(RawApiAccessMode::FULL);
    }

    public function tearDown(): void
    {
        McpTestHelper::setRawApiAccessMode($this->originalRawApiAccessMode);

        parent::tearDown();
    }

    public function testEveryRegisteredToolInputSchemaConformsToMcpMetaschema(): void
    {
        $tools = $this->listRegisteredToolsAsRawObjects();
        self::assertNotEmpty($tools, 'Expected at least one registered MCP tool.');

        $metaschema = $this->prepareMetaschema();

        $failures = [];
        foreach ($tools as $tool) {
            $errors = $this->validateAgainstMetaschema($tool->inputSchema, $metaschema);
            if ($errors !== []) {
                $failures[$tool->name] = $errors;
            }
        }

        if ($failures !== []) {
            self::fail($this->formatConformanceFailures('inputSchema', $failures));
        }
    }

    public function testEveryRegisteredToolOutputSchemaConformsToMcpMetaschema(): void
    {
        $tools = $this->listRegisteredToolsAsRawObjects();
        $metaschema = $this->prepareMetaschema();

        $failures = [];
        $checked = 0;
        foreach ($tools as $tool) {
            // Cast to array so PHPStan's array-shape narrowing of the optional
            // `outputSchema` key works under `checkDynamicProperties: true`.
            /** @var array{outputSchema?: object} $vars */
            $vars = (array) $tool;
            if (!isset($vars['outputSchema'])) {
                continue;
            }
            $checked++;

            $errors = $this->validateAgainstMetaschema($vars['outputSchema'], $metaschema);
            if ($errors !== []) {
                $failures[$tool->name] = $errors;
            }
        }

        self::assertGreaterThan(
            0,
            $checked,
            'Expected at least one registered tool to declare an outputSchema.',
        );

        if ($failures !== []) {
            self::fail($this->formatConformanceFailures('outputSchema', $failures));
        }
    }

    /**
     * @return list<ConformanceError>
     */
    private function validateAgainstMetaschema(object $data, object $metaschema): array
    {
        $result = (new Validator())->validate($data, $metaschema);
        if ($result->isValid()) {
            return [];
        }

        $errors = [];
        $topError = $result->error();
        if ($topError !== null) {
            $this->collectLeafErrors($topError, $errors);
        }

        return $errors;
    }

    private function prepareMetaschema(): object
    {
        $json = json_encode(McpToolSchemaMetaschema::get(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        assert(is_object($decoded));

        return $decoded;
    }

    /**
     * @param list<ConformanceError> $collected
     */
    private function collectLeafErrors(ValidationError $error, array &$collected): void
    {
        $subErrors = $error->subErrors();
        if ($subErrors === []) {
            $collected[] = [
                'pointer' => $this->formatJsonPointer($error->data()->fullPath()),
                'keyword' => $error->keyword(),
                'message' => $this->formatErrorMessage($error),
            ];
            return;
        }
        foreach ($subErrors as $subError) {
            $this->collectLeafErrors($subError, $collected);
        }
    }

    /**
     * @param array<int, int|string> $path
     */
    private function formatJsonPointer(array $path): string
    {
        if ($path === []) {
            return '/';
        }

        $escaped = array_map(
            static fn($component): string => str_replace(['~', '/'], ['~0', '~1'], (string) $component),
            $path,
        );

        return '/' . implode('/', $escaped);
    }

    private function formatErrorMessage(ValidationError $error): string
    {
        $template = $error->message() ?: 'Constraint `' . $error->keyword() . '` failed.';
        $args = $error->args();

        return preg_replace_callback(
            '/\{(\w+)\}/',
            static function (array $match) use ($args): string {
                $value = $args[$match[1]] ?? '{' . $match[1] . '}';
                if (is_array($value)) {
                    return json_encode($value, JSON_UNESCAPED_SLASHES) ?: '{' . $match[1] . '}';
                }
                return (string) $value;
            },
            $template,
        ) ?? $template;
    }

    /**
     * @param array<string, list<ConformanceError>> $failures
     */
    private function formatConformanceFailures(string $schemaKind, array $failures): string
    {
        $lines = [
            sprintf('MCP metaschema %s conformance failed for %d tool(s):', $schemaKind, count($failures)),
            '',
        ];

        foreach ($failures as $toolName => $errors) {
            $lines[] = sprintf('  %s:', $toolName);
            foreach ($errors as $err) {
                $lines[] = sprintf(
                    '    - [%s] at %s — %s',
                    $err['keyword'],
                    $err['pointer'],
                    $err['message'],
                );
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Decode the tools/list response body with assoc=false so the wire-level
     * distinction between JSON objects (`{}` → stdClass) and JSON arrays
     * (`[]` → array) is preserved. The standard parser path through
     * `MessageFactory::create` uses assoc=true and collapses both to `[]`,
     * which would hide the very bug class this test is meant to catch.
     *
     * @return list<ToolEntry>
     */
    private function listRegisteredToolsAsRawObjects(): array
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('schema-conformance');
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $body = McpTestHelper::getResponseBody($response);

        $decoded = json_decode($body, false, 512, JSON_THROW_ON_ERROR);
        assert(is_object($decoded));
        /** @var object{result: object{tools: array<mixed>}} $decoded */

        /** @var list<ToolEntry> $tools */
        $tools = array_values($decoded->result->tools);

        return $tools;
    }
}
