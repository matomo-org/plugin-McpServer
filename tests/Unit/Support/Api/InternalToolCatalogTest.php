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
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Request;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Page;
use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Matomo\Dependencies\McpServer\Mcp\Server\Handler\Request\RequestHandlerInterface;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionInterface;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Server\InternalAccess;
use Piwik\Plugins\McpServer\Support\Api\InternalToolCatalog;

/**
 * @group McpServer
 * @group Plugins
 */
class InternalToolCatalogTest extends TestCase
{
    public function testBuildReturnsFlatEntriesForFullyAnnotatedTools(): void
    {
        $inputSchema = [
            'type' => 'object',
            'properties' => ['idSite' => ['type' => 'integer']],
            'required' => ['idSite'],
        ];
        $outputSchema = ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]];
        $tool = new Tool(
            name: 'matomo_demo_tool',
            title: 'Demo',
            inputSchema: $inputSchema,
            description: 'Demo description.',
            annotations: new ToolAnnotations(
                readOnlyHint: true,
                destructiveHint: false,
                idempotentHint: true,
                openWorldHint: false,
            ),
            outputSchema: $outputSchema,
        );

        $entries = (new InternalToolCatalog())->build($this->createAccess([$tool]));

        self::assertSame([[
            'name' => 'matomo_demo_tool',
            'title' => 'Demo',
            'description' => 'Demo description.',
            'inputSchema' => $inputSchema,
            'outputSchema' => $outputSchema,
            'readOnly' => true,
            'destructive' => false,
            'idempotent' => true,
            'openWorld' => false,
        ]], $entries);
    }

    public function testBuildPassesThroughNullForMissingAnnotationsObject(): void
    {
        $inputSchema = [
            'type' => 'object',
            'properties' => ['idSite' => ['type' => 'integer']],
            'required' => ['idSite'],
        ];
        $tool = new Tool(
            name: 'matomo_unannotated_tool',
            title: null,
            inputSchema: $inputSchema,
            description: 'no hints declared',
            annotations: null,
        );

        $entries = (new InternalToolCatalog())->build($this->createAccess([$tool]));

        self::assertSame([[
            'name' => 'matomo_unannotated_tool',
            'title' => null,
            'description' => 'no hints declared',
            'inputSchema' => $inputSchema,
            'outputSchema' => null,
            'readOnly' => null,
            'destructive' => null,
            'idempotent' => null,
            'openWorld' => null,
        ]], $entries);
    }

    public function testBuildPassesThroughNullForPartiallyAnnotatedTool(): void
    {
        // A tool that declared only readOnly and openWorld must surface those
        // two booleans verbatim and leave the other hints as null so consumers
        // can apply their own "unknown means …" policy instead of inheriting a
        // catalogue-imposed default.
        $inputSchema = [
            'type' => 'object',
            'properties' => ['idSite' => ['type' => 'integer']],
            'required' => ['idSite'],
        ];
        $tool = new Tool(
            name: 'matomo_partially_annotated_tool',
            title: null,
            inputSchema: $inputSchema,
            description: 'only some hints declared',
            annotations: new ToolAnnotations(
                readOnlyHint: true,
                destructiveHint: null,
                idempotentHint: null,
                openWorldHint: false,
            ),
        );

        $entries = (new InternalToolCatalog())->build($this->createAccess([$tool]));

        self::assertSame([[
            'name' => 'matomo_partially_annotated_tool',
            'title' => null,
            'description' => 'only some hints declared',
            'inputSchema' => $inputSchema,
            'outputSchema' => null,
            'readOnly' => true,
            'destructive' => null,
            'idempotent' => null,
            'openWorld' => false,
        ]], $entries);
    }

    public function testBuildReportsArgumentLessToolsWithAnEmptySchemaObject(): void
    {
        // The SDK normalizes an empty `properties` map to a stdClass so it
        // JSON-encodes as `{}`; the catalogue passes that through untouched,
        // for the same reason it preserves an explicit placeholder: a consumer
        // re-encoding the schema must not emit the spec-invalid `[]`.
        $tool = new Tool(
            name: 'matomo_argument_less_tool',
            title: null,
            inputSchema: ['type' => 'object', 'properties' => [], 'required' => null],
            description: 'takes no arguments',
            annotations: null,
        );

        $entries = (new InternalToolCatalog())->build($this->createAccess([$tool]));

        self::assertCount(1, $entries);
        self::assertInstanceOf(\stdClass::class, $entries[0]['inputSchema']['properties']);

        $encoded = json_encode($entries[0]['inputSchema']);
        self::assertIsString($encoded);
        self::assertStringContainsString('"properties":{}', $encoded);
    }

    public function testBuildPreservesStdClassPlaceholdersInSchemas(): void
    {
        // A tool that uses `new \stdClass()` in its schemas to force JSON `{}`
        // for an "any value" fragment must reach the consumer with that
        // stdClass intact; if the catalogue silently flattened it to `[]`,
        // a consumer re-encoding the schema for an external MCP client would
        // emit a spec-invalid JSON Schema fragment.
        $defaultValuePlaceholder = new \stdClass();
        $resultPlaceholder = new \stdClass();
        $inputSchema = [
            'type' => 'object',
            'properties' => ['defaultValue' => $defaultValuePlaceholder],
            'required' => ['defaultValue'],
        ];
        $outputSchema = [
            'type' => 'object',
            'properties' => ['result' => $resultPlaceholder],
            'required' => ['result'],
        ];
        $tool = new Tool(
            name: 'matomo_any_value_tool',
            title: null,
            inputSchema: $inputSchema,
            description: 'mirrors stdClass placeholders',
            annotations: null,
            outputSchema: $outputSchema,
        );

        $entries = (new InternalToolCatalog())->build($this->createAccess([$tool]));

        self::assertCount(1, $entries);
        $entryInputSchema = $entries[0]['inputSchema'];
        $entryOutputSchema = $entries[0]['outputSchema'];
        self::assertIsArray($entryInputSchema['properties']);
        self::assertIsArray($entryOutputSchema);
        self::assertIsArray($entryOutputSchema['properties']);
        self::assertSame($defaultValuePlaceholder, $entryInputSchema['properties']['defaultValue']);
        self::assertSame($resultPlaceholder, $entryOutputSchema['properties']['result']);

        // Re-encoding the catalogue must keep the placeholders as JSON `{}`,
        // not `[]` — that is the whole point of the passthrough contract.
        $encoded = json_encode($entries);
        self::assertIsString($encoded);
        self::assertStringContainsString('"defaultValue":{}', $encoded);
        self::assertStringContainsString('"result":{}', $encoded);
    }

    /**
     * @param list<Tool> $tools
     */
    private function createAccess(array $tools): InternalAccess
    {
        $references = [];
        foreach ($tools as $tool) {
            $references[$tool->name] = $tool;
        }

        $registry = $this->createMock(RegistryInterface::class);
        $registry->method('getTools')->willReturn(new Page($references, null));

        $callToolHandler = new class implements RequestHandlerInterface {
            public function supports(Request $request): bool
            {
                return false;
            }

            public function handle(Request $request, SessionInterface $session): Response|Error
            {
                throw new \LogicException('Not needed for catalog tests.');
            }
        };

        return new InternalAccess($registry, $callToolHandler);
    }
}
