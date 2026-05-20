<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\McpTools;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryQueryRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\ApiGet;
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;
use Piwik\Plugins\McpServer\SystemSettings;
use stdClass;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiGetTest extends TestCase
{
    public function testGetReturnsRecordFromMethodSelector(): void
    {
        $captured = new stdClass();
        $captured->values = [];

        $tool = new ApiGet(
            new class ($captured) implements ApiMethodSummaryQueryServiceInterface {
                public function __construct(private stdClass $captured)
                {
                }

                public function getApiMethodSummaries(ApiMethodSummaryQueryRecord $query): array
                {
                    return [];
                }

                public function getApiMethodSummaryBySelector(
                    string $accessMode,
                    ?string $method = null,
                    ?string $module = null,
                    ?string $action = null,
                ): ApiMethodSummaryRecord {
                    $this->captured->values = [
                        'accessMode' => $accessMode,
                        'method' => $method,
                        'module' => $module,
                        'action' => $action,
                    ];

                    return new ApiMethodSummaryRecord(
                        module: 'API',
                        action: 'getMatomoVersion',
                        method: 'API.getMatomoVersion',
                        parameters: [],
                        operationCategory: ApiMethodOperationClassifier::CATEGORY_READ,
                        classificationConfidence: ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                        classificationReason: 'action-prefix:get',
                    );
                }
            },
            $this->createSystemSettingsStub('read'),
        );

        $actual = $tool->execute(method: ' API.getMatomoVersion ');

        self::assertSame([
            'module' => 'API',
            'action' => 'getMatomoVersion',
            'method' => 'API.getMatomoVersion',
            'parameters' => [],
            'operationCategory' => 'read',
        ], $actual);
        /** @var array<string, mixed> $capturedValues */
        $capturedValues = $captured->values;
        self::assertSame('read', $capturedValues['accessMode']);
        self::assertSame(' API.getMatomoVersion ', $capturedValues['method']);
        self::assertNull($capturedValues['module']);
        self::assertNull($capturedValues['action']);
    }

    public function testGetReturnsRecordFromModuleAndActionSelectors(): void
    {
        $captured = new stdClass();
        $captured->values = [];

        $tool = new ApiGet(
            new class ($captured) implements ApiMethodSummaryQueryServiceInterface {
                public function __construct(private stdClass $captured)
                {
                }

                public function getApiMethodSummaries(ApiMethodSummaryQueryRecord $query): array
                {
                    return [];
                }

                public function getApiMethodSummaryBySelector(
                    string $accessMode,
                    ?string $method = null,
                    ?string $module = null,
                    ?string $action = null,
                ): ApiMethodSummaryRecord {
                    $this->captured->values = [
                        'accessMode' => $accessMode,
                        'method' => $method,
                        'module' => $module,
                        'action' => $action,
                    ];

                    return new ApiMethodSummaryRecord(
                        module: 'UsersManager',
                        action: 'addUser',
                        method: 'UsersManager.addUser',
                        parameters: [],
                        operationCategory: ApiMethodOperationClassifier::CATEGORY_CREATE,
                        classificationConfidence: ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                        classificationReason: 'action-prefix:add',
                    );
                }
            },
            $this->createSystemSettingsStub('full'),
        );

        $actual = $tool->execute(module: ' UsersManager ', action: ' addUser ');

        self::assertSame([
            'module' => 'UsersManager',
            'action' => 'addUser',
            'method' => 'UsersManager.addUser',
            'parameters' => [],
            'operationCategory' => 'create',
        ], $actual);
        /** @var array<string, mixed> $capturedValues */
        $capturedValues = $captured->values;
        self::assertSame('full', $capturedValues['accessMode']);
        self::assertNull($capturedValues['method']);
        self::assertSame(' UsersManager ', $capturedValues['module']);
        self::assertSame(' addUser ', $capturedValues['action']);
    }

    private function createSystemSettingsStub(string $rawApiAccessMode): SystemSettings
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('getRawApiAccessMode')
            ->willReturn($rawApiAccessMode);

        return $settings;
    }
}
