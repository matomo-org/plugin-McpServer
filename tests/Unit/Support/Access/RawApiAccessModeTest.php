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
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;

/**
 * @group McpServer
 * @group Plugins
 */
class RawApiAccessModeTest extends TestCase
{
    public function testNormalizeFallsBackToDefaultForInvalidValues(): void
    {
        self::assertSame(RawApiAccessMode::NONE, RawApiAccessMode::normalize(null));
        self::assertSame(RawApiAccessMode::NONE, RawApiAccessMode::normalize([]));
        self::assertSame(RawApiAccessMode::NONE, RawApiAccessMode::normalize('invalid'));
    }

    public function testNormalizeAcceptsSupportedValuesCaseInsensitively(): void
    {
        self::assertSame(RawApiAccessMode::NONE, RawApiAccessMode::normalize(' NONE '));
        self::assertSame(RawApiAccessMode::READ, RawApiAccessMode::normalize('Read'));
        self::assertSame(RawApiAccessMode::CREATE, RawApiAccessMode::normalize('CREATE'));
        self::assertSame(RawApiAccessMode::UPDATE, RawApiAccessMode::normalize('update'));
        self::assertSame(RawApiAccessMode::DELETE, RawApiAccessMode::normalize(' Delete '));
        self::assertSame(RawApiAccessMode::FULL, RawApiAccessMode::normalize('FULL'));
        self::assertSame(
            RawApiAccessMode::READ . ',' . RawApiAccessMode::UPDATE,
            RawApiAccessMode::normalize(' update, read '),
        );
    }

    public function testFromBooleansReturnsCanonicalModes(): void
    {
        self::assertSame(RawApiAccessMode::NONE, RawApiAccessMode::fromBooleans(false, false, false, false, false));
        self::assertSame(RawApiAccessMode::READ, RawApiAccessMode::fromBooleans(true, false, false, false, false));
        self::assertSame(
            RawApiAccessMode::READ . ',' . RawApiAccessMode::UPDATE,
            RawApiAccessMode::fromBooleans(true, false, true, false, false),
        );
        self::assertSame(RawApiAccessMode::FULL, RawApiAccessMode::fromBooleans(false, false, false, false, true));
    }

    public function testAllowsCategoryUsesExplicitCrudSelection(): void
    {
        self::assertTrue(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::FULL, ApiMethodOperationClassifier::CATEGORY_DELETE),
        );

        self::assertTrue(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::READ, ApiMethodOperationClassifier::CATEGORY_READ),
        );
        self::assertFalse(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::READ, ApiMethodOperationClassifier::CATEGORY_CREATE),
        );

        self::assertTrue(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::CREATE, ApiMethodOperationClassifier::CATEGORY_CREATE),
        );
        self::assertFalse(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::CREATE, ApiMethodOperationClassifier::CATEGORY_READ),
        );
        self::assertFalse(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::CREATE, ApiMethodOperationClassifier::CATEGORY_UPDATE),
        );

        self::assertTrue(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::UPDATE, ApiMethodOperationClassifier::CATEGORY_UPDATE),
        );
        self::assertFalse(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::UPDATE, ApiMethodOperationClassifier::CATEGORY_READ),
        );
        self::assertTrue(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::DELETE, ApiMethodOperationClassifier::CATEGORY_DELETE),
        );
        self::assertFalse(
            RawApiAccessMode::allowsCategory(RawApiAccessMode::DELETE, ApiMethodOperationClassifier::CATEGORY_READ),
        );
        self::assertTrue(
            RawApiAccessMode::allowsCategory('read,update', ApiMethodOperationClassifier::CATEGORY_READ),
        );
        self::assertTrue(
            RawApiAccessMode::allowsCategory('read,update', ApiMethodOperationClassifier::CATEGORY_UPDATE),
        );
        self::assertFalse(RawApiAccessMode::allowsCategory(RawApiAccessMode::NONE, 'read'));
        self::assertFalse(RawApiAccessMode::allowsCategory(RawApiAccessMode::READ, null));
    }
}
