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
use Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\InputRequiredResult;
use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\Session;
use Matomo\Dependencies\McpServer\Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\McpTools\ApiCallRead;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\Schemas\Api\ApiCallToolInputSchema;
use Piwik\Plugins\McpServer\Server\Handler\Request\CompatibleCallToolHandler;

/**
 * Coverage of intake recovery through the public call path: the arguments a client sends,
 * validated against the tools' advertised schemas.
 *
 * The advertised schemas stay canonical - none of the recovered spellings appear in them - so
 * these cases also pin that recovery happens before validation rather than by loosening what
 * the tools publish.
 *
 * @group McpServer
 * @group Plugins
 */
class CompatibleCallToolHandlerNormalizationTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $captured = null;

    private int $callCount = 0;

    public function testRecoveredReportRequestReachesTheToolInCanonicalForm(): void
    {
        $response = $this->callReportTool([
            'idSite' => '1',
            'period' => 'month',
            'date' => 'lastMonth',
            'reportUniqueId' => 'Actions.getPageUrls',
            'expanded' => true,
            'filter_sort_column' => 'nb_visits',
            'filter_sort_order' => 'desc',
            'filterLimit' => '25',
            'apiParameters' => '{"segment":"browserCode==FF"}',
        ]);

        self::assertSuccessful($response);
        self::assertSame(1, $this->callCount);
        self::assertSame([
            'idSite' => 1,
            'period' => 'month',
            'date' => 'lastMonth',
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiParameters' => [
                'expanded' => true,
                'filter_sort_column' => 'nb_visits',
                'filter_sort_order' => 'desc',
            ],
            'segment' => 'browserCode==FF',
            'filter_limit' => 25,
        ], $this->captured);
    }

    public function testCanonicalAndRecoveredRequestsProduceTheSameToolArguments(): void
    {
        $this->callReportTool([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiParameters' => ['expanded' => true],
            'filter_limit' => 25,
        ]);
        $canonical = $this->captured;

        $this->callReportTool([
            'idSite' => '1',
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions.getPageUrls',
            'expanded' => true,
            'filterLimit' => '25',
        ]);

        self::assertSame($canonical, $this->captured);
    }

    public function testRedundantReportSelectorsRemainSchemaInvalid(): void
    {
        $response = $this->callReportTool([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
        ]);

        self::assertInstanceOf(Error::class, $response);
        self::assertSame(Error::INVALID_PARAMS, $response->code);
        self::assertSame(0, $this->callCount);
    }

    public function testConflictingReportSelectorsRemainSchemaInvalid(): void
    {
        $response = $this->callReportTool([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'VisitsSummary_get',
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
        ]);

        self::assertInstanceOf(Error::class, $response);
        self::assertSame(Error::INVALID_PARAMS, $response->code);
        self::assertSame(0, $this->callCount);
    }

    /**
     * A string that looks like an object but does not decode is rejected as `isError`, not as
     * `-32602`: `applyObjectFields()` attempts the decode rather than leaving the string in place.
     */
    public function testMalformedJsonObjectStringPerformsNoCall(): void
    {
        $response = $this->callReportTool([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiParameters' => '{"expanded":true',
        ]);

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertTrue($response->result->isError);
        self::assertSame(0, $this->callCount);

        // The message names the pointer, never the string: a serialised parameter object is
        // where a pasted token or segment expression can appear.
        $content = $response->result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $content);
        self::assertIsString($content->text);
        self::assertStringContainsString('/apiParameters', $content->text);
        self::assertStringNotContainsString('expanded', $content->text);
    }

    /**
     * Recovery is attempted only for a string that visibly opens as an object. Anything else -
     * valid JSON that is not an object, or a bare word - keeps its schema type error on `-32602`.
     *
     * @param array<string, mixed> $arguments
     *
     * @dataProvider provideNonObjectParameterStrings
     */
    public function testNonObjectParameterStringKeepsItsSchemaTypeError(array $arguments): void
    {
        $response = $this->callReportTool(array_merge([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions_getPageUrls',
        ], $arguments));

        self::assertInstanceOf(Error::class, $response);
        self::assertSame(Error::INVALID_PARAMS, $response->code);
        self::assertSame(0, $this->callCount);

        // Rejected by the schema for the field in question, not incidentally by something else.
        self::assertIsArray($response->data);
        self::assertStringContainsString(
            'apiParameters',
            (string) ($response->data['validation_errors'][0]['pointer'] ?? ''),
        );
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function provideNonObjectParameterStrings(): iterable
    {
        yield 'valid JSON that is not an object' => [['apiParameters' => '[1,2]']];
        yield 'bare word' => [['apiParameters' => 'flat']];
        yield 'quoted scalar' => [['apiParameters' => '"expanded"']];
    }

    public function testConflictingParameterLocationsPerformNoCall(): void
    {
        $response = $this->callReportTool([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions_getPageUrls',
            'expanded' => true,
            'apiParameters' => ['expanded' => false],
        ]);

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertTrue($response->result->isError);
        self::assertSame(0, $this->callCount);
    }

    /**
     * Recovery is silent: a request whose selector was rewritten returns the same result as one
     * that was already canonical, carrying no `_meta` for a client to branch on.
     */
    public function testRecoveredRequestReportsNothingAboutTheRewrite(): void
    {
        foreach (['Actions_getPageUrls', 'Actions.getPageUrls'] as $suppliedUniqueId) {
            $response = $this->callReportTool([
                'idSite' => 1,
                'period' => 'day',
                'date' => 'yesterday',
                'reportUniqueId' => $suppliedUniqueId,
            ]);

            self::assertSuccessful($response);
            self::assertInstanceOf(Response::class, $response);
            self::assertInstanceOf(CallToolResult::class, $response->result);
            self::assertNull($response->result->meta, $suppliedUniqueId);
        }
    }

    /**
     * A list where the schema declares an object stays rejected. Relocating a top-level parameter
     * into it would make it a mixed-key array, which validates as an object, so recovery would be
     * the reason a schema constraint stopped applying.
     */
    public function testRelocationDoesNotRescueAListParameterContainer(): void
    {
        $response = $this->callReportTool([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiParameters' => ['a', 'b'],
            'expanded' => true,
        ]);

        self::assertInstanceOf(Error::class, $response);
        self::assertSame(Error::INVALID_PARAMS, $response->code);
        self::assertSame(0, $this->callCount);
    }

    /**
     * `null` where the schema declares an object is not a registered shape, so a relocation does
     * not repair it into one: otherwise the same input would succeed or fail depending on whether
     * an unrelated top-level parameter was relocated into it.
     */
    public function testRelocationDoesNotRescueANullParameterContainer(): void
    {
        $response = $this->callReportTool([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiParameters' => null,
            'expanded' => true,
        ]);

        self::assertInstanceOf(Error::class, $response);
        self::assertSame(Error::INVALID_PARAMS, $response->code);
        self::assertSame(0, $this->callCount);
    }

    public function testUnregisteredLookalikeStillFailsValidation(): void
    {
        $response = $this->callReportTool([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions_getPageUrls',
            'filter_Limit' => 25,
        ]);

        self::assertInstanceOf(Error::class, $response);
        self::assertSame(Error::INVALID_PARAMS, $response->code);
        self::assertSame(0, $this->callCount);
    }

    /**
     * Recovery consumes a selector only when it can read it. A selector key holding a value the
     * advertised schema forbids reaches validation in either direction, alias or canonical.
     *
     * @param array<string, mixed> $arguments
     *
     * @dataProvider provideSchemaInvalidSelectorCompanions
     */
    public function testSelectorWithSchemaInvalidValueStillFailsValidation(array $arguments): void
    {
        $response = $this->callReportTool(array_merge([
            'idSite' => 1,
            'period' => 'day',
            'date' => 'yesterday',
            'reportUniqueId' => 'Actions_getPageUrls',
        ], $arguments));

        self::assertInstanceOf(Error::class, $response);
        self::assertSame(Error::INVALID_PARAMS, $response->code);
        self::assertSame(0, $this->callCount);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function provideSchemaInvalidSelectorCompanions(): iterable
    {
        yield 'method alias' => [['method' => 'Actions.getPageUrls']];
        yield 'report alias' => [['report' => 'Actions_getPageUrls']];
    }

    public function testRawApiSelectorConverges(): void
    {
        $response = $this->callApiReadTool([
            'method' => 'VisitsSummary.get',
            'module' => 'VisitsSummary',
            'parameters' => '{"idSite":1,"period":"day","date":"yesterday"}',
        ]);

        self::assertSuccessful($response);
        self::assertSame(1, $this->callCount);
        self::assertSame([
            'method' => 'VisitsSummary.get',
            'parameters' => ['idSite' => 1, 'period' => 'day', 'date' => 'yesterday'],
        ], $this->captured);
    }

    /**
     * The profile registers `parameters` as an object field, so the raw-API tools accept `[]`
     * where the schema declares `{}`.
     */
    public function testRawApiEmptyParameterListIsAcceptedAsAnEmptyObject(): void
    {
        $response = $this->callApiReadTool([
            'method' => 'VisitsSummary.get',
            'parameters' => [],
        ]);

        self::assertSuccessful($response);
        self::assertSame(1, $this->callCount);
        self::assertSame(['method' => 'VisitsSummary.get', 'parameters' => []], $this->captured);
    }

    /**
     * `module` + `action` is permitted by the advertised schema, so it reaches the tool exactly
     * as sent and carries no `_meta`, as
     * {@see testRecoveredRequestReportsNothingAboutTheRewrite} asserts for the report tool.
     */
    public function testRawApiModuleAndActionFormIsDispatchedUnchangedWithoutMeta(): void
    {
        $response = $this->callApiReadTool([
            'module' => 'VisitsSummary',
            'action' => 'get',
        ]);

        self::assertSuccessful($response);
        self::assertSame(['module' => 'VisitsSummary', 'action' => 'get'], $this->captured);

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertNull($response->result->meta);
    }

    public function testRawApiSelectorPartWithSchemaInvalidValueStillFailsValidation(): void
    {
        $response = $this->callApiReadTool([
            'method' => 'VisitsSummary.get',
            'module' => 'VisitsSummary',
            'action' => ['x'],
        ]);

        self::assertInstanceOf(Error::class, $response);
        self::assertSame(Error::INVALID_PARAMS, $response->code);
        self::assertSame(0, $this->callCount);
    }

    public function testRawApiConflictingSelectorPerformsNoCall(): void
    {
        $response = $this->callApiReadTool([
            'method' => 'VisitsSummary.get',
            'module' => 'Actions',
        ]);

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertTrue($response->result->isError);
        self::assertSame(0, $this->callCount);
    }

    /**
     * Every `integer`-typed argument the processed-report tool declares reaches it as an int
     * when a client stringifies it, on the canonical spelling and through the paging aliases.
     *
     * Asserted over the public call path, because two steps outside the intake profiles produce
     * this together: `CompatibleCallToolHandler::coerceIntegerStringsForValidation()` promotes the
     * string past the schema's `integer` check, and the SDK's `ReferenceHandler::castToInt()`
     * retypes it against `execute()`'s reflected `int` parameter on dispatch.
     */
    public function testStringifiedIntegerArgumentsReachTheToolAsIntegers(): void
    {
        $captured = null;
        $response = $this->call(
            ReportProcessed::TOOL_NAME,
            ReportProcessed::class,
            $this->reportInputSchema(),
            [
                'idSite' => '1',
                'period' => 'day',
                'date' => 'yesterday',
                'reportUniqueId' => 'Actions_getPageUrls',
                'idSubtable' => '7',
                'filterLimit' => '25',
                'filterOffset' => '10',
            ],
            function (
                int $idSite,
                string $period,
                string $date,
                ?string $reportUniqueId = null,
                ?string $apiModule = null,
                ?string $apiAction = null,
                ?array $apiParameters = null,
                ?string $goalMetricsMode = null,
                ?array $goalMetricsProcessGoals = null,
                ?string $segment = null,
                int|string|null $idGoal = null,
                ?int $idDimension = null,
                ?int $idSubtable = null,
                ?int $filter_limit = null,
                ?int $filter_offset = null,
            ) use (&$captured): array {
                $captured = [
                    'idSite' => $idSite,
                    'idSubtable' => $idSubtable,
                    'filter_limit' => $filter_limit,
                    'filter_offset' => $filter_offset,
                ];

                return [];
            },
        );

        self::assertSuccessful($response);
        self::assertSame([
            'idSite' => 1,
            'idSubtable' => 7,
            'filter_limit' => 25,
            'filter_offset' => 10,
        ], $captured);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return Response<CallToolResult|InputRequiredResult>|Error
     */
    private function callReportTool(array $arguments): Response|Error
    {
        $this->captured = null;

        return $this->call(
            ReportProcessed::TOOL_NAME,
            ReportProcessed::class,
            $this->reportInputSchema(),
            $arguments,
            function (
                int $idSite,
                string $period,
                string $date,
                ?string $reportUniqueId = null,
                ?string $apiModule = null,
                ?string $apiAction = null,
                ?array $apiParameters = null,
                ?string $goalMetricsMode = null,
                ?array $goalMetricsProcessGoals = null,
                ?string $segment = null,
                int|string|null $idGoal = null,
                ?int $idDimension = null,
                ?int $idSubtable = null,
                ?int $filter_limit = null,
                ?int $filter_offset = null,
            ): array {
                $this->callCount++;
                $this->captured = self::withoutNulls([
                    'idSite' => $idSite,
                    'period' => $period,
                    'date' => $date,
                    'reportUniqueId' => $reportUniqueId,
                    'apiModule' => $apiModule,
                    'apiAction' => $apiAction,
                    'apiParameters' => $apiParameters,
                    'segment' => $segment,
                    'filter_limit' => $filter_limit,
                ]);

                return $this->captured;
            },
        );
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return Response<CallToolResult|InputRequiredResult>|Error
     */
    private function callApiReadTool(array $arguments): Response|Error
    {
        $this->captured = null;

        return $this->call(
            ApiCallRead::TOOL_NAME,
            ApiCallRead::class,
            ApiCallToolInputSchema::SCHEMA,
            $arguments,
            function (
                ?string $method = null,
                ?string $module = null,
                ?string $action = null,
                ?array $parameters = null,
            ): array {
                $this->callCount++;
                $this->captured = self::withoutNulls([
                    'method' => $method,
                    'module' => $module,
                    'action' => $action,
                    'parameters' => $parameters,
                ]);

                return $this->captured;
            },
        );
    }

    /**
     * @param class-string $toolClass
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed> $arguments
     *
     * @return Response<CallToolResult|InputRequiredResult>|Error
     */
    private function call(
        string $toolName,
        string $toolClass,
        array $inputSchema,
        array $arguments,
        \Closure $tool,
    ): Response|Error {
        $registry = new Registry();
        $registry->registerTool(new Tool($toolName, null, self::asToolSchema($inputSchema), null, null), $tool);

        return $this->handleWith($registry, $arguments, $toolName, $toolClass);
    }

    /**
     * The class map stands in for what McpServerFactory collects while registering the real tool
     * objects: profiles are keyed on the class, so a name alone earns no normalization.
     *
     * @param array<string, mixed> $arguments
     * @param class-string $toolClass
     *
     * @return Response<CallToolResult|InputRequiredResult>|Error
     */
    private function handleWith(
        Registry $registry,
        array $arguments,
        string $toolName,
        string $toolClass,
    ): Response|Error {
        $handler = new CompatibleCallToolHandler(
            $registry,
            new ReferenceHandler(),
            toolClasses: [$toolName => $toolClass],
        );
        $request = (new CallToolRequest($toolName, $arguments))->withId('normalization-' . $this->callCount);

        return $handler->handle(
            $request,
            new Session(new InMemorySessionStore(), Uuid::fromString('4bd0ff21-3d5c-4a5a-9b1e-2c4f6a8d0e13')),
        );
    }

    /**
     * @param array<string, mixed> $inputSchema
     *
     * @return array{type: 'object', properties: array<string, mixed>, required: array<string>|null}
     */
    private static function asToolSchema(array $inputSchema): array
    {
        /** @var array{type: 'object', properties: array<string, mixed>, required: array<string>|null} $toolSchema */
        $toolSchema = $inputSchema;

        return $toolSchema;
    }

    /**
     * The processed-report tool reads only static schema data in init(), so a stub query service
     * is enough to obtain the schema the server advertises.
     *
     * @return array<string, mixed>
     */
    private function reportInputSchema(): array
    {
        $queryService = $this->createStub(ReportProcessedQueryServiceInterface::class);

        return (new ReportProcessed($queryService))->getInputSchema();
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function withoutNulls(array $values): array
    {
        return array_filter($values, static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param Response<CallToolResult|InputRequiredResult>|Error $response
     */
    private static function assertSuccessful(Response|Error $response): void
    {
        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError, 'Expected a successful tool result.');
    }
}
