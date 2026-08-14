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
use Piwik\Plugins\McpServer\Support\Normalization\ArgumentIssueException;
use Piwik\Plugins\McpServer\Support\Normalization\ReportSelectorConvergence;
use Piwik\Plugins\McpServer\Support\Normalization\SelectorConvergenceInterface;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportSelectorConvergenceTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     *
     * @dataProvider provideAcceptedSelectors
     */
    public function testAcceptedSelectorsCollapseToCanonicalUniqueId(array $input, array $expected): void
    {
        $arguments = (new ReportSelectorConvergence())->converge($input);

        self::assertSame($expected, $arguments);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function provideAcceptedSelectors(): iterable
    {
        yield 'canonical unique id' => [
            ['reportUniqueId' => 'Actions_getPageUrls'],
            ['reportUniqueId' => 'Actions_getPageUrls'],
        ];

        yield 'dot form in reportUniqueId' => [
            ['reportUniqueId' => 'Actions.getPageUrls'],
            ['reportUniqueId' => 'Actions_getPageUrls'],
        ];

        yield 'dot form of a parameterized report id' => [
            ['reportUniqueId' => 'Goals.get_idGoal--1'],
            ['reportUniqueId' => 'Goals_get_idGoal--1'],
        ];

        yield 'module and action alone are left to their own resolution path' => [
            ['apiModule' => 'Goals', 'apiAction' => 'get'],
            ['apiModule' => 'Goals', 'apiAction' => 'get'],
        ];

        // The pair reaches the metadata lookup itself here, and that compares exactly.
        yield 'padded module and action alone are canonicalised' => [
            ['apiModule' => ' Goals ', 'apiAction' => " get\n"],
            ['apiModule' => 'Goals', 'apiAction' => 'get'],
        ];
    }

    /**
     * Report unique IDs do not encode an authoritative module/action boundary. Every companion
     * therefore survives convergence for the canonical schema to reject, whether it looks equal,
     * conflicting, partial, or is not a schema-valid string.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $expected
     *
     * @dataProvider provideUniqueIdsWithCompanions
     */
    public function testUniqueIdCompanionsSurviveConvergence(array $input, array $expected): void
    {
        $arguments = (new ReportSelectorConvergence())->converge($input);

        self::assertSame($expected, $arguments);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function provideUniqueIdsWithCompanions(): iterable
    {
        yield 'apparently matching module and action' => [
            ['reportUniqueId' => 'Actions_getPageUrls', 'apiModule' => 'Actions', 'apiAction' => 'getPageUrls'],
            ['reportUniqueId' => 'Actions_getPageUrls', 'apiModule' => 'Actions', 'apiAction' => 'getPageUrls'],
        ];

        yield 'parameterized id split at the wrong boundary' => [
            ['reportUniqueId' => 'Goals_get_idGoal--1', 'apiModule' => 'Goals_get', 'apiAction' => 'idGoal--1'],
            ['reportUniqueId' => 'Goals_get_idGoal--1', 'apiModule' => 'Goals_get', 'apiAction' => 'idGoal--1'],
        ];

        yield 'partial companion' => [
            ['reportUniqueId' => 'VisitsSummary_get', 'apiModule' => 'VisitsSummary'],
            ['reportUniqueId' => 'VisitsSummary_get', 'apiModule' => 'VisitsSummary'],
        ];

        yield 'conflicting companions' => [
            ['reportUniqueId' => 'VisitsSummary_get', 'apiModule' => 'Actions', 'apiAction' => 'getPageUrls'],
            ['reportUniqueId' => 'VisitsSummary_get', 'apiModule' => 'Actions', 'apiAction' => 'getPageUrls'],
        ];

        yield 'schema-invalid companion' => [
            ['reportUniqueId' => 'Actions_getPageUrls', 'apiModule' => 'Actions', 'apiAction' => ['x']],
            ['reportUniqueId' => 'Actions_getPageUrls', 'apiModule' => 'Actions', 'apiAction' => ['x']],
        ];

        yield 'dot form still canonicalises without consuming companions' => [
            ['reportUniqueId' => 'Actions.getPageUrls', 'apiModule' => 'Actions', 'apiAction' => 'getPageUrls'],
            ['reportUniqueId' => 'Actions_getPageUrls', 'apiModule' => 'Actions', 'apiAction' => 'getPageUrls'],
        ];

        yield 'declined dotted form retains companions' => [
            ['reportUniqueId' => 'My_Plugin.get', 'apiModule' => 'My_Plugin', 'apiAction' => 'get'],
            ['reportUniqueId' => 'My_Plugin.get', 'apiModule' => 'My_Plugin', 'apiAction' => 'get'],
        ];
    }

    /**
     * Convergence runs before schema validation, so every selector form it reads applies the
     * intake length gate itself.
     *
     * @param array<string, mixed> $arguments
     *
     * @dataProvider provideOversizedSelectors
     */
    public function testAnOversizedSelectorIsRejectedBeforeSchemaValidation(
        array $arguments,
        string $expectedPath,
    ): void {
        try {
            (new ReportSelectorConvergence())->converge($arguments);
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

        yield 'oversized unique id' => [
            ['reportUniqueId' => $oversized],
            '/reportUniqueId',
        ];

        yield 'oversized api action in a module/action pair' => [
            ['apiModule' => 'Actions', 'apiAction' => $oversized],
            '/apiAction',
        ];

        yield 'oversized api module in a module/action pair' => [
            ['apiModule' => $oversized, 'apiAction' => 'getPageUrls'],
            '/apiModule',
        ];
    }

    /**
     * The gate is a ceiling, not a tightening: a selector exactly at the bound still converges.
     */
    public function testASelectorAtTheLengthBoundIsAccepted(): void
    {
        $uniqueId = str_repeat('a', SelectorConvergenceInterface::MAX_SELECTOR_LENGTH);

        $arguments = (new ReportSelectorConvergence())->converge(['reportUniqueId' => $uniqueId]);

        self::assertSame(['reportUniqueId' => $uniqueId], $arguments);
    }

    /**
     * Trimming and the dot conversion both land on the canonical ID, in that order: the
     * conversion has to see the trimmed value or it would decline the padded one.
     */
    public function testSurroundingWhitespaceIsCanonicalised(): void
    {
        $arguments = (new ReportSelectorConvergence())->converge(['reportUniqueId' => "  Actions.getPageUrls\n"]);

        self::assertSame(['reportUniqueId' => 'Actions_getPageUrls'], $arguments);
    }

    /**
     * The pair reaches the metadata lookup itself when no unique ID is supplied, and that
     * lookup compares exactly, so each padded key is trimmed on its own.
     */
    public function testPaddedModuleAndActionPairIsTrimmedPerKey(): void
    {
        $arguments = (new ReportSelectorConvergence())->converge(['apiModule' => ' Goals ', 'apiAction' => " get\n"]);

        self::assertSame(['apiModule' => 'Goals', 'apiAction' => 'get'], $arguments);
    }

    public function testALonePaddedModuleOrActionIsLeftForSchemaValidation(): void
    {
        foreach ([['apiModule' => ' Actions '], ['apiAction' => " getPageUrls\n"]] as $input) {
            $arguments = (new ReportSelectorConvergence())->converge($input);

            self::assertSame($input, $arguments);
        }
    }

    /**
     * A wrong underscore ID stays unresolved: the catalogue lookup rejects it rather than this
     * class picking a near neighbour.
     *
     * @dataProvider provideUncorrectedSelectors
     */
    public function testNoFuzzyCorrectionIsApplied(string $suppliedUniqueId): void
    {
        $arguments = (new ReportSelectorConvergence())->converge(['reportUniqueId' => $suppliedUniqueId]);

        self::assertSame($suppliedUniqueId, $arguments['reportUniqueId']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideUncorrectedSelectors(): iterable
    {
        yield 'wrong module' => ['Pages_getPageTitles'];
        yield 'wrong action' => ['Actions_getSearches'];
        yield 'wrong module for a real action' => ['Ecommerce_getConversions'];
        yield 'dot form with an extra segment' => ['Actions.getPageUrls.extra'];
        yield 'underscore form with a trailing dotted segment' => ['Actions_getPageUrls.extra'];
        yield 'parameterized underscore form with a trailing dotted segment' => ['Goals_get_idGoal--1.5'];
        // Splicing interior whitespace through would fabricate `Actions _ getPageUrls`.
        yield 'dot form with whitespace before the separator' => ['Actions . getPageUrls'];
        yield 'dot form with whitespace after the separator' => ['Actions. getPageUrls'];
    }
}
