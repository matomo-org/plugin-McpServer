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
        $cases = [
            [
                'SitesManager.setDefaultTimezone',
                'setDefaultTimezone',
                ApiMethodOperationClassifier::CATEGORY_UPDATE,
                'action-prefix:set',
            ],
            [
                'UsersManager.inviteUser',
                'inviteUser',
                ApiMethodOperationClassifier::CATEGORY_CREATE,
                'action-prefix:invite',
            ],
            [
                'LanguagesManager.uses12HourClockForUser',
                'uses12HourClockForUser',
                ApiMethodOperationClassifier::CATEGORY_READ,
                'action-prefix:uses',
            ],
            [
                'CustomJsTracker.doesIncludePluginTrackersAutomatically',
                'doesIncludePluginTrackersAutomatically',
                ApiMethodOperationClassifier::CATEGORY_READ,
                'action-prefix:does',
            ],
            [
                'Example.startAction',
                'startAction',
                ApiMethodOperationClassifier::CATEGORY_UPDATE,
                'action-prefix:start',
            ],
            [
                'Example.finishAction',
                'finishAction',
                ApiMethodOperationClassifier::CATEGORY_UPDATE,
                'action-prefix:finish',
            ],
            [
                'Example.mergeAction',
                'mergeAction',
                ApiMethodOperationClassifier::CATEGORY_UPDATE,
                'action-prefix:merge',
            ],
            [
                'Example.unmergeAction',
                'unmergeAction',
                ApiMethodOperationClassifier::CATEGORY_UPDATE,
                'action-prefix:unmerge',
            ],
            [
                'Example.duplicateEntity',
                'duplicateEntity',
                ApiMethodOperationClassifier::CATEGORY_CREATE,
                'action-prefix:duplicate',
            ],
            [
                'Example.testAction',
                'testAction',
                ApiMethodOperationClassifier::CATEGORY_READ,
                'action-prefix:test',
            ],
            [
                'Example.duplicateResource',
                'duplicateResource',
                ApiMethodOperationClassifier::CATEGORY_CREATE,
                'action-prefix:duplicate',
            ],
            [
                'Example.rotateCredential',
                'rotateCredential',
                ApiMethodOperationClassifier::CATEGORY_UPDATE,
                'action-prefix:rotate',
            ],
        ];

        foreach ($cases as [$method, $action, $expectedCategory, $expectedReason]) {
            $classification = ApiMethodOperationClassifier::classify($method, $action);

            self::assertSame($expectedCategory, $classification['operationCategory']);
            self::assertSame(
                ApiMethodOperationClassifier::CONFIDENCE_MEDIUM,
                $classification['classificationConfidence'],
            );
            self::assertSame($expectedReason, $classification['classificationReason']);
        }
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

    public function testClassifyLeavesImportPrefixUnclassified(): void
    {
        $classification = ApiMethodOperationClassifier::classify(
            'Example.importThing',
            'importThing',
        );

        self::assertNull($classification['operationCategory']);
        self::assertSame(ApiMethodOperationClassifier::CONFIDENCE_LOW, $classification['classificationConfidence']);
        self::assertSame('unsupported-action-prefix:import', $classification['classificationReason']);
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
