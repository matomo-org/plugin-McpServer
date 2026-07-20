<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Reports;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Reports\PeriodDateNormalizer;

/**
 * @group McpServer
 * @group Plugins
 */
class PeriodDateNormalizerTest extends TestCase
{
    /**
     * @dataProvider provideCases
     */
    public function testNormalize(string $period, string $date, string $expected): void
    {
        self::assertSame($expected, PeriodDateNormalizer::normalize($period, $date));
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideCases(): iterable
    {
        // Shorthand that gets expanded to the bucket anchor date.
        yield 'year shorthand' => ['year', '2026', '2026-01-01'];
        yield 'month shorthand' => ['month', '2026-01', '2026-01-01'];
        yield 'period case-insensitive' => ['Year', '2026', '2026-01-01'];

        // Surrounding whitespace is trimmed before matching.
        yield 'year shorthand with whitespace' => ['year', ' 2026 ', '2026-01-01'];
        yield 'month shorthand with whitespace' => ['month', ' 2026-01 ', '2026-01-01'];
        yield 'keyword with whitespace' => ['year', ' lastYear ', 'lastYear'];

        // Already a full date: untouched.
        yield 'year full date' => ['year', '2026-01-01', '2026-01-01'];
        yield 'month full date' => ['month', '2026-01-15', '2026-01-15'];

        // Non year/month periods: untouched.
        yield 'day' => ['day', '2026', '2026'];
        yield 'week' => ['week', '2026-01', '2026-01'];
        yield 'range' => ['range', '2026-01-01,2026-03-31', '2026-01-01,2026-03-31'];

        // Keywords, relative and list expressions: untouched.
        yield 'year keyword' => ['year', 'lastYear', 'lastYear'];
        yield 'month keyword' => ['month', 'lastMonth', 'lastMonth'];
        yield 'year lastN' => ['year', 'last3', 'last3'];
        yield 'month comma list' => ['month', '2026-01,2026-02', '2026-01,2026-02'];

        // Shapes that do not match the shorthand pattern: untouched.
        yield 'year with month' => ['year', '2026-01', '2026-01'];
        yield 'month year only' => ['month', '2026', '2026'];
        yield 'month single-digit month' => ['month', '2026-1', '2026-1'];
    }
}
