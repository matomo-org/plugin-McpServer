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
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

/**
 * @group McpServer
 * @group Plugins
 */
class ToolDataNormalizerTest extends TestCase
{
    public function testRequireStringFieldThrowsWhenMissing(): void
    {
        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Site data is incomplete (missing 'name').");

        ToolDataNormalizer::requireStringField([], 'name', 'Site data');
    }

    public function testRequireIntLikeFieldThrowsWhenInvalid(): void
    {
        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Site data is invalid (field 'idsite').");

        ToolDataNormalizer::requireIntLikeField(['idsite' => 'abc'], 'idsite', 'Site data');
    }

    public function testRequireIntLikeFieldAcceptsNumericString(): void
    {
        self::assertSame(123, ToolDataNormalizer::requireIntLikeField(['idsite' => '123'], 'idsite', 'Site data'));
    }

    public function testRequireStringFieldAllowsEmptyString(): void
    {
        self::assertSame('', ToolDataNormalizer::requireStringField(['name' => ''], 'name', 'Site data'));
    }

    public function testRequireBoolLikeFieldConvertsExpectedValues(): void
    {
        self::assertTrue(ToolDataNormalizer::requireBoolLikeField(['sitesearch' => 1], 'sitesearch', 'Site data'));
        self::assertFalse(ToolDataNormalizer::requireBoolLikeField(['sitesearch' => '0'], 'sitesearch', 'Site data'));
        self::assertTrue(ToolDataNormalizer::requireBoolLikeField(['sitesearch' => true], 'sitesearch', 'Site data'));
    }

    public function testRequireBoolLikeFieldThrowsWhenInvalid(): void
    {
        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Site data is invalid (field 'sitesearch').");

        ToolDataNormalizer::requireBoolLikeField(['sitesearch' => 'yes'], 'sitesearch', 'Site data');
    }

    public function testRequireStringKeyedArrayOrEmptyListAcceptsEmptyList(): void
    {
        self::assertSame([], ToolDataNormalizer::requireStringKeyedArrayOrEmptyList([], 'apiParameters'));
    }

    public function testRequireStringKeyedArrayOrEmptyListRejectsNonEmptyList(): void
    {
        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('apiParameters is invalid.');

        ToolDataNormalizer::requireStringKeyedArrayOrEmptyList(['flat'], 'apiParameters');
    }
}
