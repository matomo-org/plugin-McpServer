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
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiCallQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiCallRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\ApiCallFull;
use Piwik\Plugins\McpServer\McpTools\ApiCallRead;
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;
use Piwik\Plugins\McpServer\SystemSettings;
use stdClass;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiCallTest extends TestCase
{
    public function testCallUsesMethodSelector(): void
    {
        $captured = new stdClass();
        $captured->values = [];

        $record = new ApiMethodSummaryRecord(
            'API',
            'getMatomoVersion',
            'API.getMatomoVersion',
            [],
            ApiMethodOperationClassifier::CATEGORY_READ,
            ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            'action-prefix:get',
        );

        $tool = new ApiCallRead(
            new class ($captured) implements ApiCallQueryServiceInterface {
                public function __construct(private stdClass $captured)
                {
                }

                public function callApi(
                    ApiMethodSummaryRecord $resolvedMethod,
                    ?array $parameters = null,
                ): ApiCallRecord {
                    $this->captured->values = [
                        'resolvedMethod' => $resolvedMethod,
                        'parameters' => $parameters,
                    ];

                    return new ApiCallRecord('6.0.0', $this->createRecord());
                }

                private function createRecord(): ApiMethodSummaryRecord
                {
                    return new ApiMethodSummaryRecord(
                        'API',
                        'getMatomoVersion',
                        'API.getMatomoVersion',
                        [],
                        ApiMethodOperationClassifier::CATEGORY_READ,
                        ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                        'action-prefix:get',
                    );
                }
            },
            $this->createMethodSummaryQueryServiceStub($record),
            $this->createSystemSettingsStub('read'),
        );

        $actual = $tool->execute(method: ' API.getMatomoVersion ');

        self::assertSame([
            'result' => '6.0.0',
            'resolvedMethod' => [
                'module' => 'API',
                'action' => 'getMatomoVersion',
                'method' => 'API.getMatomoVersion',
                'parameters' => [],
                'operationCategory' => 'read',
            ],
        ], $actual);
        /** @var array<string, mixed> $capturedValues */
        $capturedValues = $captured->values;
        self::assertInstanceOf(ApiMethodSummaryRecord::class, $capturedValues['resolvedMethod']);
        self::assertSame('API.getMatomoVersion', $capturedValues['resolvedMethod']->method);
        self::assertNull($capturedValues['parameters']);
    }

    public function testCallUsesSplitSelectorAndParameters(): void
    {
        $captured = new stdClass();
        $captured->values = [];

        $record = new ApiMethodSummaryRecord(
            'UsersManager',
            'addUser',
            'UsersManager.addUser',
            [],
            ApiMethodOperationClassifier::CATEGORY_CREATE,
            ApiMethodOperationClassifier::CONFIDENCE_HIGH,
            'action-prefix:add',
        );

        $tool = new ApiCallFull(
            new class ($captured) implements ApiCallQueryServiceInterface {
                public function __construct(private stdClass $captured)
                {
                }

                public function callApi(
                    ApiMethodSummaryRecord $resolvedMethod,
                    ?array $parameters = null,
                ): ApiCallRecord {
                    $this->captured->values = [
                        'resolvedMethod' => $resolvedMethod,
                        'parameters' => $parameters,
                    ];

                    return new ApiCallRecord(
                        ['success' => true],
                        new ApiMethodSummaryRecord(
                            'UsersManager',
                            'addUser',
                            'UsersManager.addUser',
                            [],
                            ApiMethodOperationClassifier::CATEGORY_CREATE,
                            ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                            'action-prefix:add',
                        ),
                    );
                }
            },
            $this->createMethodSummaryQueryServiceStub($record),
            $this->createSystemSettingsStub('full'),
        );

        $actual = $tool->execute(
            module: ' UsersManager ',
            action: ' addUser ',
            parameters: ['userLogin' => 'alice'],
        );

        self::assertSame(['success' => true], $actual['result']);
        /** @var array<string, mixed> $capturedValues */
        $capturedValues = $captured->values;
        self::assertInstanceOf(ApiMethodSummaryRecord::class, $capturedValues['resolvedMethod']);
        self::assertSame('UsersManager.addUser', $capturedValues['resolvedMethod']->method);
        self::assertSame(['userLogin' => 'alice'], $capturedValues['parameters']);
    }

    public function testScopedToolRejectsMethodOutsideExpectedOperationCategory(): void
    {
        $tool = new ApiCallRead(
            $this->createMock(ApiCallQueryServiceInterface::class),
            $this->createMethodSummaryQueryServiceStub(new ApiMethodSummaryRecord(
                'UsersManager',
                'addUser',
                'UsersManager.addUser',
                [],
                ApiMethodOperationClassifier::CATEGORY_CREATE,
                ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                'action-prefix:add',
            )),
            $this->createSystemSettingsStub('full'),
        );

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('API method not found or unavailable.');

        $tool->execute(method: 'UsersManager.addUser');
    }

    private function createMethodSummaryQueryServiceStub(
        ApiMethodSummaryRecord $record,
    ): ApiMethodSummaryQueryServiceInterface {
        $service = $this->createMock(ApiMethodSummaryQueryServiceInterface::class);
        $service->method('getApiMethodSummaryBySelector')
            ->willReturn($record);

        return $service;
    }

    private function createSystemSettingsStub(string $rawApiAccessMode): SystemSettings
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('getRawApiAccessMode')
            ->willReturn($rawApiAccessMode);

        return $settings;
    }
}
