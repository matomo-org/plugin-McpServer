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
use Piwik\Plugins\McpServer\Support\Normalization\ApiSelectorConvergence;
use Piwik\Plugins\McpServer\Support\Normalization\ArgumentIssueException;
use Piwik\Plugins\McpServer\Support\Normalization\SelectorConvergenceInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiSelectorConvergenceTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     *
     * @dataProvider provideAcceptedSelectors
     */
    public function testAcceptedSelectorsCollapseToCanonicalMethod(array $input, string $expectedMethod): void
    {
        $arguments = (new ApiSelectorConvergence())->converge($input);

        self::assertSame($expectedMethod, $arguments['method']);
        self::assertArrayNotHasKey('module', $arguments);
        self::assertArrayNotHasKey('action', $arguments);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function provideAcceptedSelectors(): iterable
    {
        yield 'method only' => [
            ['method' => 'VisitsSummary.get'],
            'VisitsSummary.get',
        ];

        yield 'method with a redundant module drops the module' => [
            ['method' => 'VisitsSummary.get', 'module' => 'VisitsSummary'],
            'VisitsSummary.get',
        ];

        yield 'method with redundant module and action' => [
            ['method' => 'VisitsSummary.get', 'module' => 'VisitsSummary', 'action' => 'get'],
            'VisitsSummary.get',
        ];

        yield 'method with redundant action only' => [
            ['method' => 'VisitsSummary.get', 'action' => 'get'],
            'VisitsSummary.get',
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $expectedPaths
     *
     * @dataProvider provideRejectedSelectors
     */
    public function testRejectedSelectors(array $input, string $expectedReason, array $expectedPaths): void
    {
        try {
            (new ApiSelectorConvergence())->converge($input);
        } catch (ArgumentIssueException $e) {
            self::assertSame($expectedReason, $e->reason);
            self::assertSame($expectedPaths, $e->paths);

            return;
        }

        self::fail('Expected an ArgumentIssueException.');
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: list<string>}>
     */
    public static function provideRejectedSelectors(): iterable
    {
        yield 'method conflicts with module' => [
            ['method' => 'VisitsSummary.get', 'module' => 'Actions'],
            ArgumentIssueException::REASON_CONFLICTING_SELECTORS,
            ['/method', '/module'],
        ];

        yield 'method conflicts with action' => [
            ['method' => 'VisitsSummary.get', 'action' => 'getPageUrls'],
            ArgumentIssueException::REASON_CONFLICTING_SELECTORS,
            ['/method', '/action'],
        ];

        yield 'method with a trailing segment is a syntax error' => [
            ['method' => 'VisitsSummary.get.extra'],
            ArgumentIssueException::REASON_INVALID_SELECTOR_SYNTAX,
            ['/method'],
        ];

        // Comparing segment-by-segment would be more lenient than the whole-string catalogue
        // lookup, so an internally padded method is a shape error rather than something a
        // companion `module` collapses against.
        yield 'whitespace after the separator is a syntax error' => [
            ['method' => 'VisitsSummary. get', 'module' => 'VisitsSummary'],
            ArgumentIssueException::REASON_INVALID_SELECTOR_SYNTAX,
            ['/method'],
        ];

        yield 'whitespace before the separator is a syntax error' => [
            ['method' => 'VisitsSummary .get'],
            ArgumentIssueException::REASON_INVALID_SELECTOR_SYNTAX,
            ['/method'],
        ];

        yield 'method without a separator is a syntax error' => [
            ['method' => 'VisitsSummary'],
            ArgumentIssueException::REASON_INVALID_SELECTOR_SYNTAX,
            ['/method'],
        ];
    }

    /**
     * `module` + `action` is a selector form the schema permits and the tool resolves on its own,
     * so convergence leaves it byte-identical.
     *
     * @param array<string, mixed> $input
     *
     * @dataProvider provideAlreadyValidSelectors
     */
    public function testAlreadyValidSelectorsAreLeftUntouched(array $input): void
    {
        $arguments = (new ApiSelectorConvergence())->converge($input);

        self::assertSame($input, $arguments);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function provideAlreadyValidSelectors(): iterable
    {
        yield 'module and action' => [['module' => 'VisitsSummary', 'action' => 'get']];

        // The catalogue lookup compares strtolower(trim()), so it tolerates the padding itself.
        yield 'padded method' => [['method' => "  VisitsSummary.get\n"]];

        yield 'padded module and action' => [['module' => ' VisitsSummary ', 'action' => ' get ']];
    }

    /**
     * Collapsing a redundant part consumes that part and nothing else: `method` keeps the exact
     * value the caller sent, padding included, since the catalogue lookup trims it.
     */
    public function testCollapsingARedundantPartLeavesMethodExactlyAsSupplied(): void
    {
        $arguments = (new ApiSelectorConvergence())->converge(
            ['method' => "  VisitsSummary.get\n", 'module' => 'VisitsSummary'],
        );

        self::assertSame(['method' => "  VisitsSummary.get\n"], $arguments);
    }

    public function testCaseAndWhitespaceDifferencesAreNotConflicts(): void
    {
        $arguments = (new ApiSelectorConvergence())->converge(
            ['method' => 'VisitsSummary.get', 'module' => ' visitssummary '],
        );

        self::assertSame('VisitsSummary.get', $arguments['method']);
    }

    public function testLoneModuleIsLeftForSchemaValidation(): void
    {
        $arguments = (new ApiSelectorConvergence())->converge(['module' => 'VisitsSummary']);

        self::assertSame(['module' => 'VisitsSummary'], $arguments);
    }

    /**
     * A selector part holding a value the schema forbids is not consumed: dropping it would hide
     * an argument that `minLength` or the selector truth table rejects.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     *
     * @dataProvider provideSelectorsWithUnreadableCompanionValues
     */
    public function testSelectorPartsWithSchemaInvalidValuesSurviveConvergence(
        array $input,
        array $expected,
    ): void {
        $arguments = (new ApiSelectorConvergence())->converge($input);

        self::assertSame($expected, $arguments);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function provideSelectorsWithUnreadableCompanionValues(): iterable
    {
        yield 'non-string action beside a matching module' => [
            ['method' => 'VisitsSummary.get', 'module' => 'VisitsSummary', 'action' => ['x']],
            ['method' => 'VisitsSummary.get', 'action' => ['x']],
        ];

        yield 'empty module beside a matching action' => [
            ['method' => 'VisitsSummary.get', 'module' => '', 'action' => 'get'],
            ['method' => 'VisitsSummary.get', 'module' => ''],
        ];

        yield 'non-string module alone is left for schema validation' => [
            ['method' => 'VisitsSummary.get', 'module' => 123],
            ['method' => 'VisitsSummary.get', 'module' => 123],
        ];
    }

    /**
     * The same length gate the report side applies, for the same reason: convergence precedes
     * schema validation, so this is the only bound `method` meets before parseMethod() runs its
     * pattern over it.
     *
     * @param array<string, mixed> $arguments
     *
     * @dataProvider provideOversizedSelectors
     */
    public function testAnOversizedSelectorIsRejectedBeforeParsing(
        array $arguments,
        string $expectedPath,
    ): void {
        try {
            (new ApiSelectorConvergence())->converge($arguments);
            self::fail('Expected an ArgumentIssueException.');
        } catch (ArgumentIssueException $e) {
            self::assertSame(ArgumentIssueException::REASON_SELECTOR_TOO_LONG, $e->reason);
            self::assertSame([$expectedPath], $e->paths);
            self::assertStringNotContainsString('aaaa', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function provideOversizedSelectors(): iterable
    {
        $oversized = str_repeat('a', SelectorConvergenceInterface::MAX_SELECTOR_LENGTH + 1);

        yield 'oversized method' => [['method' => $oversized], '/method'];

        yield 'oversized module beside a short method' => [
            ['method' => 'VisitsSummary.get', 'module' => $oversized],
            '/module',
        ];

        yield 'oversized action beside a short method' => [
            ['method' => 'VisitsSummary.get', 'action' => $oversized],
            '/action',
        ];
    }

    /**
     * The gate is a ceiling, not a tightening: a method exactly at the bound still reaches the
     * ordinary `Module.action` syntax check rather than the length one.
     */
    public function testAMethodAtTheLengthBoundStillReachesTheSyntaxCheck(): void
    {
        $method = str_repeat('a', SelectorConvergenceInterface::MAX_SELECTOR_LENGTH);

        try {
            (new ApiSelectorConvergence())->converge(['method' => $method]);
            self::fail('Expected an ArgumentIssueException.');
        } catch (ArgumentIssueException $e) {
            self::assertSame(ArgumentIssueException::REASON_INVALID_SELECTOR_SYNTAX, $e->reason);
        }
    }
}
