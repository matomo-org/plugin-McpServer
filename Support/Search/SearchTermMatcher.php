<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Search;

/**
 * Separator-insensitive substring matching shared by the discovery tools
 * (matomo_report_list, matomo_api_list).
 *
 * Callers reach for a report or method by whatever spelling is in front of
 * them - the human name ("Visits Summary"), the camelCase id ("VisitsSummary"),
 * or the dotted method ("VisitsSummary.get"). Reducing both the needle and each
 * haystack to a key that drops the connectors that vary between those forms -
 * whitespace, underscores, hyphens, dots - lets a single term match regardless
 * of which spelling the caller used. Letters (including non-ASCII) and digits
 * are preserved, so translated names stay searchable.
 */
final class SearchTermMatcher
{
    /**
     * Reduce a value to its comparison key: lowercased and stripped of the
     * separators that distinguish otherwise-equivalent spellings.
     */
    public static function key(?string $value): string
    {
        // mb_strtolower, not strtolower: report and method names surface in the
        // active Matomo language, so an ASCII-only lowercasing would leave
        // accented capitals (e.g. "ÉVÉNEMENTS") uppercased and fail to match
        // their lowercase spelling.
        $lowered = mb_strtolower(trim((string) $value), 'UTF-8');

        return (string) preg_replace('~[\s._-]+~u', '', $lowered);
    }

    /**
     * True when the already-normalized needle key appears in any haystack. An
     * empty needle matches everything, mirroring an absent filter.
     *
     * @param string ...$haystacks raw field values, normalized here
     */
    public static function matches(string $needleKey, string ...$haystacks): bool
    {
        if ($needleKey === '') {
            return true;
        }

        foreach ($haystacks as $haystack) {
            if (str_contains(self::key($haystack), $needleKey)) {
                return true;
            }
        }

        return false;
    }
}
