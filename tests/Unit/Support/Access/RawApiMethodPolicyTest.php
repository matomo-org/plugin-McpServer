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
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;

/**
 * @group McpServer
 * @group Plugins
 */
class RawApiMethodPolicyTest extends TestCase
{
    public function testAllowsMethodUsesExplicitCrudClassificationForNonFullModes(): void
    {
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::READ,
                'UsersManager.getUsers',
                'getUsers',
                ApiMethodOperationClassifier::CATEGORY_READ,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
        );
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::READ,
                'SitesManager.isSiteNameUnique',
                'isSiteNameUnique',
                ApiMethodOperationClassifier::CATEGORY_READ,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
        );
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::READ,
                'UsersManager.hasSuperUserAccess',
                'hasSuperUserAccess',
                ApiMethodOperationClassifier::CATEGORY_READ,
                ApiMethodOperationClassifier::CONFIDENCE_MEDIUM,
            ),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::READ,
                'UsersManager.addUser',
                'addUser',
                ApiMethodOperationClassifier::CATEGORY_CREATE,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
        );
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::CREATE,
                'UsersManager.addUser',
                'addUser',
                ApiMethodOperationClassifier::CATEGORY_CREATE,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
        );
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::UPDATE,
                'SitesManager.setDefaultTimezone',
                'setDefaultTimezone',
                ApiMethodOperationClassifier::CATEGORY_UPDATE,
                ApiMethodOperationClassifier::CONFIDENCE_MEDIUM,
            ),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::UPDATE,
                'UsersManager.getUsers',
                'getUsers',
                ApiMethodOperationClassifier::CATEGORY_READ,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
        );
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::UPDATE,
                'UsersManager.addUser',
                'addUser',
                ApiMethodOperationClassifier::CATEGORY_CREATE,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
        );
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::READ . ',' . RawApiAccessMode::UPDATE,
                'UsersManager.getUsers',
                'getUsers',
                ApiMethodOperationClassifier::CATEGORY_READ,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
        );
    }

    public function testAllowsMethodLetsFullModeUseNonDeniedMethods(): void
    {
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::FULL,
                'UsersManager.hasSuperUserAccess',
                'hasSuperUserAccess',
                ApiMethodOperationClassifier::CATEGORY_READ,
                ApiMethodOperationClassifier::CONFIDENCE_MEDIUM,
            ),
        );
        self::assertTrue(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::FULL,
                'UsersManager.addUser',
                'addUser',
                ApiMethodOperationClassifier::CATEGORY_CREATE,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
        );
    }

    public function testAllowsMethodRejectsDeniedMethodsInReadAndFullModes(): void
    {
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::READ,
                'API.getProcessedReport',
                'getProcessedReport',
                ApiMethodOperationClassifier::CATEGORY_READ,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
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
                ApiMethodOperationClassifier::CATEGORY_READ,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            ),
        );
    }

    public function testAllowsMethodRejectsLowConfidenceMethodsOutsideFull(): void
    {
        self::assertFalse(
            RawApiMethodPolicy::allowsMethod(
                RawApiAccessMode::READ,
                'ScheduledReports.sendReport',
                'sendReport',
                null,
                ApiMethodOperationClassifier::CONFIDENCE_LOW,
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
