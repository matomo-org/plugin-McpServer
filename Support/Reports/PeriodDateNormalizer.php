<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Reports;

/**
 * Expands the shorthand dates LLMs naturally produce for whole-period buckets
 * into the full YYYY-MM-DD form that Matomo's Date::factory() requires.
 *
 * Date::factory() feeds unrecognized strings to strtotime(), where a bare year
 * ("2026") parses as a time-of-day and a year-month ("2026-01") is ambiguous,
 * so those values silently resolve to the wrong date instead of the intended
 * bucket. Anchoring the expansion to the bucket-defining anchor date keeps the
 * conversion unambiguous:
 *   - period=year  + "2026"    -> "2026-01-01"
 *   - period=month + "2026-01" -> "2026-01-01"
 *
 * Anything that already includes a day, or is a keyword/relative/range/list
 * expression, is passed through untouched.
 */
final class PeriodDateNormalizer
{
    public static function normalize(string $period, string $date): string
    {
        // Trim first so surrounding whitespace ("2026 ") does not defeat the
        // shorthand match and leave the value for strtotime() to misread.
        $date = trim($date);

        return match (strtolower($period)) {
            'year' => preg_match('/^\d{4}$/', $date) === 1 ? $date . '-01-01' : $date,
            'month' => preg_match('/^\d{4}-\d{2}$/', $date) === 1 ? $date . '-01' : $date,
            default => $date,
        };
    }
}
