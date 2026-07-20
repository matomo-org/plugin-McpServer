<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Search;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Search\SearchTermMatcher;

/**
 * @group McpServer
 * @group Plugins
 */
class SearchTermMatcherTest extends TestCase
{
    /**
     * @dataProvider provideKeys
     */
    public function testKeyLowercasesAndStripsSeparators(?string $value, string $expected): void
    {
        self::assertSame($expected, SearchTermMatcher::key($value));
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public function provideKeys(): array
    {
        return [
            'null' => [null, ''],
            'blank' => ['   ', ''],
            'trims and lowercases' => ['  Visits Summary  ', 'visitssummary'],
            'strips spaces' => ['Visits Summary', 'visitssummary'],
            'strips underscores' => ['visits_summary_get', 'visitssummaryget'],
            'strips hyphens' => ['visits-summary', 'visitssummary'],
            'strips dots' => ['VisitsSummary.get', 'visitssummaryget'],
            'collapses mixed runs' => ['Visits __ - . Summary', 'visitssummary'],
            'keeps digits' => ['CustomDimension 5', 'customdimension5'],
            'preserves non-ascii letters' => ['Références', 'références'],
            // Uppercase non-ASCII must fold to its lowercase form; strtolower
            // would leave the accented capitals untouched and break matching.
            'lowercases non-ascii uppercase' => ['ÉVÉNEMENTS', 'événements'],
        ];
    }

    public function testMatchesUppercaseNonAsciiNeedleAgainstLowercaseHaystack(): void
    {
        // A localized report name typed in uppercase must still match its
        // lowercase spelling returned by Matomo.
        self::assertTrue(SearchTermMatcher::matches(
            SearchTermMatcher::key('ÉVÉNEMENTS'),
            'événements',
        ));
    }

    public function testEmptyNeedleMatchesEverything(): void
    {
        self::assertTrue(SearchTermMatcher::matches('', 'anything'));
        self::assertTrue(SearchTermMatcher::matches(''));
    }

    public function testMatchesAcrossSpellingsAndHaystacks(): void
    {
        // "Visits Summary" typed by a human normalizes to visitssummary and finds
        // the camelCase uniqueId even though the spaced needle is not a raw substring.
        self::assertTrue(SearchTermMatcher::matches(
            SearchTermMatcher::key('Visits Summary'),
            'VisitsSummary_get',
        ));

        // The dotted method spelling the model tends to guess also normalizes to a match.
        self::assertTrue(SearchTermMatcher::matches(
            SearchTermMatcher::key('VisitsSummary.get'),
            'VisitsSummary_get',
        ));

        // First haystack misses, second matches.
        self::assertTrue(SearchTermMatcher::matches(
            SearchTermMatcher::key('acquisition'),
            'All Channels',
            'Acquisition',
        ));

        self::assertFalse(SearchTermMatcher::matches(
            SearchTermMatcher::key('no such thing'),
            'Visits Summary',
            'Visitors',
            'VisitsSummary_get',
        ));
    }
}
