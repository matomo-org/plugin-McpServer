<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Api;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiMethodOperationClassifierTest extends TestCase
{
    public function testClassifyTreatsReportMetadataMethodsAsReadOperations(): void
    {
        $classification = ApiMethodOperationClassifier::classify('API.getProcessedReport', 'getProcessedReport');

        self::assertSame(ApiMethodOperationClassifier::CATEGORY_READ, $classification['operationCategory']);
        self::assertSame(ApiMethodOperationClassifier::CONFIDENCE_HIGH, $classification['classificationConfidence']);
        self::assertSame('action-prefix:get', $classification['classificationReason']);
    }

    public function testClassifyUsesHighConfidenceCrudPrefixes(): void
    {
        $cases = [
            [
                'UsersManager.getUsers',
                'getUsers',
                ApiMethodOperationClassifier::CATEGORY_READ,
                'action-prefix:get',
            ],
            [
                'UsersManager.addUser',
                'addUser',
                ApiMethodOperationClassifier::CATEGORY_CREATE,
                'action-prefix:add',
            ],
            [
                'UsersManager.updateUser',
                'updateUser',
                ApiMethodOperationClassifier::CATEGORY_UPDATE,
                'action-prefix:update',
            ],
            [
                'UsersManager.deleteUser',
                'deleteUser',
                ApiMethodOperationClassifier::CATEGORY_DELETE,
                'action-prefix:delete',
            ],
        ];

        foreach ($cases as [$method, $action, $expectedCategory, $expectedReason]) {
            $classification = ApiMethodOperationClassifier::classify($method, $action);

            self::assertSame($expectedCategory, $classification['operationCategory']);
            self::assertSame(
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                $classification['classificationConfidence'],
            );
            self::assertSame($expectedReason, $classification['classificationReason']);
        }
    }

    public function testClassifyUsesMediumConfidencePrefixes(): void
    {
        $setClassification = ApiMethodOperationClassifier::classify(
            'SitesManager.setDefaultTimezone',
            'setDefaultTimezone',
        );
        self::assertSame(ApiMethodOperationClassifier::CATEGORY_UPDATE, $setClassification['operationCategory']);
        self::assertSame(
            ApiMethodOperationClassifier::CONFIDENCE_MEDIUM,
            $setClassification['classificationConfidence'],
        );
        self::assertSame('action-prefix:set', $setClassification['classificationReason']);

        $inviteClassification = ApiMethodOperationClassifier::classify('UsersManager.inviteUser', 'inviteUser');
        self::assertSame(ApiMethodOperationClassifier::CATEGORY_CREATE, $inviteClassification['operationCategory']);
        self::assertSame(
            ApiMethodOperationClassifier::CONFIDENCE_MEDIUM,
            $inviteClassification['classificationConfidence'],
        );
        self::assertSame('action-prefix:invite', $inviteClassification['classificationReason']);

        $usesClassification = ApiMethodOperationClassifier::classify(
            'LanguagesManager.uses12HourClockForUser',
            'uses12HourClockForUser',
        );
        self::assertSame(ApiMethodOperationClassifier::CATEGORY_READ, $usesClassification['operationCategory']);
        self::assertSame(
            ApiMethodOperationClassifier::CONFIDENCE_MEDIUM,
            $usesClassification['classificationConfidence'],
        );
        self::assertSame('action-prefix:uses', $usesClassification['classificationReason']);

        $doesClassification = ApiMethodOperationClassifier::classify(
            'CustomJsTracker.doesIncludePluginTrackersAutomatically',
            'doesIncludePluginTrackersAutomatically',
        );
        self::assertSame(ApiMethodOperationClassifier::CATEGORY_READ, $doesClassification['operationCategory']);
        self::assertSame(
            ApiMethodOperationClassifier::CONFIDENCE_MEDIUM,
            $doesClassification['classificationConfidence'],
        );
        self::assertSame('action-prefix:does', $doesClassification['classificationReason']);
    }

    public function testClassifyUsesExistsSuffixAsReadFallback(): void
    {
        $classification = ApiMethodOperationClassifier::classify('UsersManager.userExists', 'userExists');

        self::assertSame(ApiMethodOperationClassifier::CATEGORY_READ, $classification['operationCategory']);
        self::assertSame(
            ApiMethodOperationClassifier::CONFIDENCE_MEDIUM,
            $classification['classificationConfidence'],
        );
        self::assertSame('action-suffix:exists', $classification['classificationReason']);
    }

    public function testClassifyLeavesUnsupportedPrefixesUnclassified(): void
    {
        $classification = ApiMethodOperationClassifier::classify('ScheduledReports.sendReport', 'sendReport');

        self::assertNull($classification['operationCategory']);
        self::assertSame(ApiMethodOperationClassifier::CONFIDENCE_LOW, $classification['classificationConfidence']);
        self::assertSame('unsupported-action-prefix:send', $classification['classificationReason']);
    }

    public function testClassifyLeavesMissingActionPrefixUnclassified(): void
    {
        $classification = ApiMethodOperationClassifier::classify('Example.123invalid', ' 123invalid ');

        self::assertNull($classification['operationCategory']);
        self::assertSame(ApiMethodOperationClassifier::CONFIDENCE_LOW, $classification['classificationConfidence']);
        self::assertSame('missing-action-prefix', $classification['classificationReason']);
    }

    public function testNormalizeCategoryAcceptsKnownValuesAndRejectsUnknownOnes(): void
    {
        self::assertSame('read', ApiMethodOperationClassifier::normalizeCategory(' READ '));
        self::assertSame('uncategorized', ApiMethodOperationClassifier::normalizeCategory(' uncategorized '));
        self::assertSame('', ApiMethodOperationClassifier::normalizeCategory('reports'));
        self::assertSame('', ApiMethodOperationClassifier::normalizeCategory('unsupported'));
    }
}
