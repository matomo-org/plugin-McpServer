<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Normalization;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\McpTools\ApiCallCreate;
use Piwik\Plugins\McpServer\McpTools\ApiCallDelete;
use Piwik\Plugins\McpServer\McpTools\ApiCallFull;
use Piwik\Plugins\McpServer\McpTools\ApiCallRead;
use Piwik\Plugins\McpServer\McpTools\ApiCallUpdate;
use Piwik\Plugins\McpServer\McpTools\ApiGet;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\Support\Normalization\ArgumentIssueException;
use Piwik\Plugins\McpServer\Support\Normalization\IntakeNormalizer;
use Piwik\Plugins\McpServer\Support\Normalization\ToolIntakeProfile;
use Piwik\Plugins\McpServer\Support\Normalization\ToolIntakeProfiles;
use Piwik\Plugins\McpServer\tests\Framework\StatefulContributedMcpTool;

/**
 * @group McpServer
 * @group Plugins
 */
class IntakeNormalizerTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     *
     * @dataProvider provideProcessedReportRows
     */
    public function testProcessedReportProfileRow(array $input, array $expected): void
    {
        self::assertSame($expected, $this->normalize(ReportProcessed::class, $input));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function provideProcessedReportRows(): iterable
    {
        $selector = ['reportUniqueId' => 'Actions_getPageUrls'];

        // An alias moves the key and carries the value over exactly as supplied.
        yield 'filterLimit key alias' => [
            $selector + ['filterLimit' => 100],
            $selector + ['filter_limit' => 100],
        ];

        yield 'filterOffset key alias carries a stringified value over unchanged' => [
            $selector + ['filterOffset' => '10'],
            $selector + ['filter_offset' => '10'],
        ];

        // Integer-typed arguments are left exactly as sent, in every spelling. The engine does
        // not retype: `coerceIntegerStringsForValidation()` promotes the string past the schema's
        // `integer` check and the SDK's ReferenceHandler retypes it on dispatch, for profiled and
        // unprofiled tools alike. Restating that here would add a third rule to keep in step with
        // the other two. CompatibleCallToolHandlerNormalizationTest pins the arrival types.
        yield 'stringified integers pass through untouched' => [
            $selector + ['idSite' => '1', 'idSubtable' => '42', 'filter_limit' => '25', 'filter_offset' => '10'],
            $selector + ['idSite' => '1', 'idSubtable' => '42', 'filter_limit' => '25', 'filter_offset' => '10'],
        ];

        // The engine leaves `[]` alone; the `{}` the schema wants is substituted for validation
        // only, by the call handler.
        yield 'apiParameters empty array is left alone' => [
            $selector + ['apiParameters' => []],
            $selector + ['apiParameters' => []],
        ];

        yield 'apiParameters JSON object string' => [
            $selector + ['apiParameters' => '{"expanded":true}'],
            $selector + ['apiParameters' => ['expanded' => true]],
        ];

        yield 'expanded relocated into apiParameters' => [
            $selector + ['expanded' => true],
            $selector + ['apiParameters' => ['expanded' => true]],
        ];

        yield 'flat relocated into apiParameters' => [
            $selector + ['flat' => true],
            $selector + ['apiParameters' => ['flat' => true]],
        ];

        yield 'sort controls relocated into apiParameters' => [
            $selector + ['filter_sort_column' => 'nb_visits', 'filter_sort_order' => 'desc'],
            $selector + ['apiParameters' => ['filter_sort_column' => 'nb_visits', 'filter_sort_order' => 'desc']],
        ];

        yield 'nested segment lifted to the top level' => [
            $selector + ['apiParameters' => ['segment' => 'browserCode==FF']],
            $selector + ['apiParameters' => [], 'segment' => 'browserCode==FF'],
        ];

        // Paging is generic-safe inside the container, so it is not lifted: moving it would
        // change which of two values wins rather than recover a rejected request.
        yield 'nested filter_limit stays in the container' => [
            $selector + ['apiParameters' => ['filter_limit' => 500]],
            $selector + ['apiParameters' => ['filter_limit' => 500]],
        ];

        yield 'nested filter_offset stays in the container' => [
            $selector + ['apiParameters' => ['filter_offset' => '20']],
            $selector + ['apiParameters' => ['filter_offset' => '20']],
        ];

        yield 'canonical arguments pass through unchanged' => [
            $selector + ['idSite' => 1, 'period' => 'day', 'date' => 'yesterday', 'filter_limit' => 50],
            $selector + ['idSite' => 1, 'period' => 'day', 'date' => 'yesterday', 'filter_limit' => 50],
        ];

        // Relocating into a list would produce a mixed-key array that passes the container's
        // `type: object` check, so the list has to stay wrong and be rejected as sent.
        yield 'non-empty list container is left for schema rejection' => [
            $selector + ['apiParameters' => ['a', 'b'], 'expanded' => true],
            $selector + ['apiParameters' => ['a', 'b'], 'expanded' => true],
        ];

        // `null` is not a registered shape for a parameter container, so a relocation does not
        // repair it into an object.
        yield 'null container is left for schema rejection' => [
            $selector + ['apiParameters' => null, 'expanded' => true],
            $selector + ['apiParameters' => null, 'expanded' => true],
        ];

        yield 'unregistered lookalike key is left for schema rejection' => [
            $selector + ['filter_Limit' => 100],
            $selector + ['filter_Limit' => 100],
        ];
    }

    /**
     * Three recoveries on one request: the camelCase paging alias, a serialised parameter
     * object, and the dot form of the selector. The engine applies no bound of its own, so
     * `filter_limit` arrives as the caller wrote it and is left for the schema's maximum to
     * reject.
     */
    public function testSeveralRecoveriesCombineIntoCanonicalArguments(): void
    {
        $result = $this->normalize(ReportProcessed::class, [
            'reportUniqueId' => 'Actions.getPageUrls',
            'idSite' => '1',
            'filterLimit' => '500',
            'apiParameters' => '{"expanded":true}',
        ]);

        self::assertSame([
            'reportUniqueId' => 'Actions_getPageUrls',
            'idSite' => '1',
            'apiParameters' => ['expanded' => true],
            'filter_limit' => '500',
        ], $result);
    }

    public function testRelocationExampleMovesControlsDownAndSegmentUp(): void
    {
        $result = $this->normalize(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'idSite' => 1,
            'period' => 'month',
            'date' => 'lastMonth',
            'expanded' => true,
            'filter_sort_column' => 'nb_visits',
            'filter_sort_order' => 'desc',
            'apiParameters' => ['segment' => 'browserCode==FF'],
        ]);

        self::assertSame([
            'reportUniqueId' => 'Actions_getPageUrls',
            'idSite' => 1,
            'period' => 'month',
            'date' => 'lastMonth',
            'apiParameters' => [
                'expanded' => true,
                'filter_sort_column' => 'nb_visits',
                'filter_sort_order' => 'desc',
            ],
            'segment' => 'browserCode==FF',
        ], $result);
    }

    /**
     * Equality compares the value a client wrote, not the JSON type it reached for, so the
     * string and the integer count as one value rather than as a contradiction.
     */
    public function testEqualAliasAndCanonicalValueCollapseToOne(): void
    {
        $result = $this->normalize(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'filterLimit' => '25',
            'filter_limit' => 25,
        ]);

        self::assertSame([
            'reportUniqueId' => 'Actions_getPageUrls',
            'filter_limit' => 25,
        ], $result);
    }

    public function testConflictingAliasAndCanonicalValueIsRejected(): void
    {
        $issue = $this->normalizeExpectingIssue(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'filterLimit' => 10,
            'filter_limit' => 20,
        ]);

        self::assertSame(ArgumentIssueException::REASON_CONFLICTING_ALIAS_VALUES, $issue->reason);
        self::assertSame(['/filterLimit', '/filter_limit'], $issue->paths);
    }

    public function testEqualParameterLocationsCollapseToOne(): void
    {
        $result = $this->normalize(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'expanded' => true,
            'apiParameters' => ['expanded' => true],
        ]);

        self::assertSame([
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiParameters' => ['expanded' => true],
        ], $result);
    }

    public function testEqualSegmentLocationsCollapseToOne(): void
    {
        $result = $this->normalize(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'segment' => 'browserCode==FF',
            'apiParameters' => ['segment' => 'browserCode==FF'],
        ]);

        self::assertSame([
            'reportUniqueId' => 'Actions_getPageUrls',
            'segment' => 'browserCode==FF',
            'apiParameters' => [],
        ], $result);
    }

    /**
     * A flag written one way at the top level and another inside the container is one value, not
     * a contradiction, Matomo's request layer reading `true`, `1` and `"1"` identically. The
     * surviving value is the container's, exactly as supplied.
     *
     * @dataProvider provideAgreeingFlagSpellings
     */
    public function testFlagSpellingsThatMeanTheSameValueDoNotConflict(
        mixed $topLevel,
        mixed $nested,
    ): void {
        $result = $this->normalize(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'expanded' => $topLevel,
            'apiParameters' => ['expanded' => $nested],
        ]);

        self::assertSame([
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiParameters' => ['expanded' => $nested],
        ], $result);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: mixed}>
     */
    public static function provideAgreeingFlagSpellings(): iterable
    {
        yield 'true beside 1' => [true, 1];
        yield '1 beside true' => [1, true];
        yield 'true beside "1"' => [true, '1'];
        yield '"1" beside true' => ['1', true];
        yield 'false beside 0' => [false, 0];
        yield '0 beside false' => [0, false];
        yield 'false beside "0"' => [false, '0'];
        yield '"0" beside false' => ['0', false];
    }

    /**
     * The fold stops where Matomo's request layer stops: parameters are read as ints, so `"true"`
     * is `0` there and cannot stand in for `true`. The near-miss numeric spellings are excluded
     * for the same reason, none of them denoting one value unambiguously.
     *
     * @dataProvider provideDisagreeingFlagSpellings
     */
    public function testSpellingsThatDoNotMeanTheSameValueStillConflict(
        mixed $topLevel,
        mixed $nested,
    ): void {
        $issue = $this->normalizeExpectingIssue(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'expanded' => $topLevel,
            'apiParameters' => ['expanded' => $nested],
        ]);

        self::assertSame(ArgumentIssueException::REASON_CONFLICTING_PARAMETER_LOCATIONS, $issue->reason);
    }

    /**
     * @return iterable<string, array{0: mixed, 1: mixed}>
     */
    public static function provideDisagreeingFlagSpellings(): iterable
    {
        yield 'the word true beside true' => ['true', true];
        yield 'the word false beside false' => ['false', false];
        yield 'true beside 0' => [true, 0];
        yield 'false beside 1' => [false, 1];
        yield 'leading zero beside its integer' => ['01', 1];
        yield 'decimal beside its integer' => ['1.5', 1];
        yield 'negative zero beside zero' => ['-0', 0];
        yield 'empty string beside false' => ['', false];
        yield 'null beside false' => [null, false];
    }

    /**
     * A negative decimal string is unambiguous and folds like any other, so the two locations
     * agree. Whether the value is *allowed* stays with the schema.
     */
    public function testNegativeIntegerSpellingsAgree(): void
    {
        $result = $this->normalize(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'filterLimit' => '-1',
            'filter_limit' => -1,
        ]);

        self::assertSame([
            'reportUniqueId' => 'Actions_getPageUrls',
            'filter_limit' => -1,
        ], $result);
    }

    public function testConflictingParameterLocationsAreRejected(): void
    {
        $issue = $this->normalizeExpectingIssue(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'expanded' => true,
            'apiParameters' => ['expanded' => false],
        ]);

        self::assertSame(ArgumentIssueException::REASON_CONFLICTING_PARAMETER_LOCATIONS, $issue->reason);
        self::assertSame(['/expanded', '/apiParameters/expanded'], $issue->paths);
    }

    public function testConflictingSegmentLocationsAreRejectedWithoutEchoingValues(): void
    {
        $issue = $this->normalizeExpectingIssue(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'segment' => 'browserCode==FF',
            'apiParameters' => ['segment' => 'countryCode==de'],
        ]);

        self::assertSame(ArgumentIssueException::REASON_CONFLICTING_PARAMETER_LOCATIONS, $issue->reason);
        self::assertSame(['/apiParameters/segment', '/segment'], $issue->paths);
        self::assertStringNotContainsString('browserCode', $issue->getMessage());
        self::assertStringNotContainsString('countryCode', $issue->getMessage());
    }

    /**
     * The decode has to run before the lift, or the segment would still be inside a string
     * when the lift looks for it.
     */
    public function testDecodedParameterStringStillHasItsSegmentLifted(): void
    {
        $result = $this->normalize(ReportProcessed::class, [
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiParameters' => '{"segment":"browserCode==FF"}',
        ]);

        self::assertSame([
            'reportUniqueId' => 'Actions_getPageUrls',
            'apiParameters' => [],
            'segment' => 'browserCode==FF',
        ], $result);
    }

    /**
     * The engine leaves `[]` alone and the tool is dispatched with `[]`. The `{}` shape the schema
     * needs is applied for validation only, from this profile's object fields
     * ({@see \Piwik\Plugins\McpServer\Server\Handler\Request\CompatibleCallToolHandler}).
     */
    public function testRawApiParametersLeaveEmptyArrayAloneAndDecodeJsonObjectString(): void
    {
        $emptyArray = $this->normalize(ApiCallRead::class, [
            'method' => 'VisitsSummary.get',
            'parameters' => [],
        ]);
        self::assertSame(['method' => 'VisitsSummary.get', 'parameters' => []], $emptyArray);

        $jsonString = $this->normalize(ApiCallRead::class, [
            'method' => 'VisitsSummary.get',
            'parameters' => '{"idSite":1,"period":"day"}',
        ]);
        self::assertSame([
            'method' => 'VisitsSummary.get',
            'parameters' => ['idSite' => 1, 'period' => 'day'],
        ], $jsonString);
    }

    /**
     * @param class-string $toolClass
     *
     * @dataProvider provideUnprofiledTools
     */
    public function testUnprofiledToolHasNoProfile(string $toolClass): void
    {
        self::assertNull(ToolIntakeProfiles::forToolClass($toolClass));
    }

    /**
     * @return iterable<string, array{0: class-string}>
     */
    public static function provideUnprofiledTools(): iterable
    {
        yield 'unrelated tool' => [SiteList::class];
    }

    /**
     * The lookup keys on the class, so a tool contributed through `McpServer.addTools` gets no
     * profile even when it registers a built-in tool name: its schema is its own, and the built-in
     * profile would rewrite arguments against a contract it does not publish.
     */
    public function testClassOutsideTheRegistryHasNoProfile(): void
    {
        self::assertNull(ToolIntakeProfiles::forToolClass(StatefulContributedMcpTool::class));
        self::assertNull(ToolIntakeProfiles::forToolClass(null));
    }

    /**
     * The five raw-API tools share one input schema, so a selector or parameter shape accepted by
     * one is accepted identically by the others.
     */
    public function testRawApiToolsShareOneProfile(): void
    {
        $read = $this->profileFor(ApiCallRead::class);

        foreach (
            [
                ApiCallCreate::class,
                ApiCallUpdate::class,
                ApiCallDelete::class,
                ApiCallFull::class,
            ] as $toolClass
        ) {
            self::assertEquals($read, $this->profileFor($toolClass), $toolClass);
        }
    }

    /**
     * The two report tools declare the same selector triple and are used in sequence, so a
     * selector spelling one recovers has to be recovered by the other.
     */
    public function testReportToolsShareOneSelectorConvergence(): void
    {
        self::assertEquals(
            $this->profileFor(ReportProcessed::class)->selectorConvergence,
            $this->profileFor(ReportMetadata::class)->selectorConvergence,
        );
    }

    /**
     * The same pairing on the raw API side: matomo_api_get is the discovery step for the call
     * tools and declares the same selector keys, so both recover the same spellings. Only the
     * convergence is shared - this tool publishes no parameter object.
     */
    public function testApiToolsShareOneSelectorConvergence(): void
    {
        self::assertEquals(
            $this->profileFor(ApiCallRead::class)->selectorConvergence,
            $this->profileFor(ApiGet::class)->selectorConvergence,
        );
        self::assertSame([], $this->profileFor(ApiGet::class)->objectFields);
    }

    /**
     * @param class-string $toolClass
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function normalize(string $toolClass, array $arguments): array
    {
        return (new IntakeNormalizer($this->profileFor($toolClass)))->normalize($arguments);
    }

    /**
     * @param class-string $toolClass
     * @param array<string, mixed> $arguments
     */
    private function normalizeExpectingIssue(string $toolClass, array $arguments): ArgumentIssueException
    {
        try {
            $this->normalize($toolClass, $arguments);
        } catch (ArgumentIssueException $e) {
            return $e;
        }

        self::fail('Expected an ArgumentIssueException.');
    }

    /**
     * @param class-string $toolClass
     */
    private function profileFor(string $toolClass): ToolIntakeProfile
    {
        $profile = ToolIntakeProfiles::forToolClass($toolClass);
        self::assertNotNull($profile, "Expected a normalization profile for {$toolClass}.");

        return $profile;
    }
}
