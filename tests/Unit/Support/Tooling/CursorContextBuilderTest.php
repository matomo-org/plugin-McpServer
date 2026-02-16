<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Tooling;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Tooling\CursorContextBuilder;

/**
 * @group McpServer
 * @group Plugins
 */
class CursorContextBuilderTest extends TestCase
{
    public function testScopeKeyOrderDoesNotAffectHash(): void
    {
        $first = CursorContextBuilder::forTool('site_list', ['idSite' => 7, 'search' => 'alpha']);
        $second = CursorContextBuilder::forTool('site_list', ['search' => 'alpha', 'idSite' => 7]);

        self::assertSame($first, $second);
    }

    public function testNullAndEmptyStringProduceDifferentHashes(): void
    {
        $withNull = CursorContextBuilder::forTool('site_search', ['search' => null]);
        $withEmpty = CursorContextBuilder::forTool('site_search', ['search' => '']);

        self::assertNotSame($withNull, $withEmpty);
    }

    public function testDelimiterLikeValuesDoNotCollide(): void
    {
        $first = CursorContextBuilder::forTool('tool', ['a' => 'b:c']);
        $second = CursorContextBuilder::forTool('tool', ['a:b' => 'c']);

        self::assertNotSame($first, $second);
    }
}
