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
use Piwik\Plugins\McpServer\Support\Access\RawApiMethodPolicy;

/**
 * @group McpServer
 * @group Plugins
 */
class RawApiMethodPolicyTest extends TestCase
{
    public function testAllowsMethodUsesReadFallbackForUnknownMethods(): void
    {
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(RawApiAccessMode::READ, 'UsersManager.getUsers', 'getUsers'),
        );
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::READ,
                'SitesManager.isSiteNameUnique',
                'isSiteNameUnique',
            ),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::READ,
                'UsersManager.hasSuperUserAccess',
                'hasSuperUserAccess',
            ),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(RawApiAccessMode::READ, 'UsersManager.addUser', 'addUser'),
        );
    }

    public function testAllowsMethodLetsFullModeUseNonDeniedMethods(): void
    {
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::FULL,
                'UsersManager.hasSuperUserAccess',
                'hasSuperUserAccess',
            ),
        );
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(RawApiAccessMode::FULL, 'UsersManager.addUser', 'addUser'),
        );
    }

    public function testAllowsMethodRejectsDeniedMethodsInReadAndFullModes(): void
    {
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(RawApiAccessMode::READ, 'API.getProcessedReport', 'getProcessedReport'),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(RawApiAccessMode::READ, 'API.getReportMetadata', 'getReportMetadata'),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(RawApiAccessMode::READ, 'API.getMetadata', 'getMetadata'),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::FULL,
                'TreemapVisualization.getTreemapData',
                'getTreemapData',
            ),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(RawApiAccessMode::FULL, 'API.getMetadata', 'getMetadata'),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(RawApiAccessMode::FULL, 'API.getReportMetadata', 'getReportMetadata'),
        );
    }

    public function testIsDeniedMethodMatchesCaseInsensitively(): void
    {
        self::assertTrue(RawApiMethodPolicy::isDeniedMethod(' API.getBulkRequest '));
        self::assertTrue(RawApiMethodPolicy::isDeniedMethod('treemapvisualization.gettreemapdata'));
        self::assertTrue(RawApiMethodPolicy::isDeniedMethod(' API.getMetadata '));
        self::assertTrue(RawApiMethodPolicy::isDeniedMethod(' API.getReportMetadata '));
    }
}
