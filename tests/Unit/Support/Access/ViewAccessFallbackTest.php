<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Access;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;

/**
 * @group McpServer
 * @group Plugins
 */
class ViewAccessFallbackTest extends TestCase
{
    public function testShouldReturnEmptyOnNoAccessFallbackForSiteIdsReturnsTrueForEmptyArray(): void
    {
        self::assertTrue(ViewAccessFallback::shouldReturnEmptyOnNoAccessFallbackForSiteIds([]));
    }

    public function testShouldReturnEmptyOnNoAccessFallbackForSiteIdsReturnsTrueForNonArray(): void
    {
        self::assertTrue(ViewAccessFallback::shouldReturnEmptyOnNoAccessFallbackForSiteIds('invalid'));
    }

    public function testShouldReturnEmptyOnNoAccessFallbackForSiteIdsReturnsFalseForAccessibleSites(): void
    {
        self::assertFalse(ViewAccessFallback::shouldReturnEmptyOnNoAccessFallbackForSiteIds([1, 2]));
    }
}
