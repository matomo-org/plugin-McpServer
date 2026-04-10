<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Logging;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Logging\ToolCallParameterFormatter;

/**
 * @group McpServer
 * @group Plugins
 */
class ToolCallParameterFormatterTest extends TestCase
{
    public function testFormatsRedactedScalarAndCollectionShapes(): void
    {
        $formatter = new ToolCallParameterFormatter();

        $formatted = $formatter->format([
            'idGoal' => 4,
            'ratio' => 1.25,
            'enabled' => true,
            'optional' => null,
            'segment' => 'country==de',
            'list' => [1, 2],
            'filter' => ['contains' => 'foo'],
            'obj' => (object) ['x' => 1],
        ], false);

        self::assertSame(
            'idGoal: <int>, ratio: <float>, enabled: <bool>, optional: <null>, '
            . 'segment: <string:11>, list: <array:2>, filter: <object:1>, obj: <object:stdClass>',
            $formatted,
        );
    }

    public function testFormatsFullValues(): void
    {
        $formatter = new ToolCallParameterFormatter();

        $formatted = $formatter->format([
            'idGoal' => 4,
            'segment' => 'country==de',
            'enabled' => true,
            'filter' => ['contains' => 'foo'],
        ], true);

        self::assertSame(
            'idGoal: 4, segment: "country==de", enabled: true, filter: {"contains":"foo"}',
            $formatted,
        );
    }

    public function testExcludesInternalArguments(): void
    {
        $formatter = new ToolCallParameterFormatter();

        $formatted = $formatter->format([
            '_session' => new \stdClass(),
            '_request' => new \stdClass(),
            'idGoal' => 4,
        ], false);

        self::assertSame('idGoal: <int>', $formatted);
    }

    public function testReturnsEmptyStringWhenNoUserArgumentsRemain(): void
    {
        $formatter = new ToolCallParameterFormatter();

        $formatted = $formatter->format([
            '_session' => new \stdClass(),
            '_request' => new \stdClass(),
        ], false);

        self::assertSame('', $formatted);
    }
}
