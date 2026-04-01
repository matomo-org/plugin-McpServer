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
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiCallQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiCallRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\ApiCall;
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

        $tool = new ApiCall(
            new class ($captured) implements ApiCallQueryServiceInterface {
                public function __construct(private stdClass $captured)
                {
                }

                public function callApi(
                    string $accessMode,
                    ?string $method = null,
                    ?string $module = null,
                    ?string $action = null,
                    ?array $parameters = null,
                ): ApiCallRecord {
                    $this->captured->values = [
                        'accessMode' => $accessMode,
                        'method' => $method,
                        'module' => $module,
                        'action' => $action,
                        'parameters' => $parameters,
                    ];

                    return new ApiCallRecord(
                        '6.0.0',
                        new ApiMethodSummaryRecord(
                            'API',
                            'getMatomoVersion',
                            'API.getMatomoVersion',
                            [],
                            ApiMethodOperationClassifier::CATEGORY_READ,
                            ApiMethodOperationClassifier::CONFIDENCE_HIGH,
                            'action-prefix:get',
                        ),
                    );
                }
            },
            $this->createSystemSettingsStub('read'),
        );

        $actual = $tool->call(method: ' API.getMatomoVersion ');

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
        self::assertSame('read', $capturedValues['accessMode']);
        self::assertSame(' API.getMatomoVersion ', $capturedValues['method']);
        self::assertNull($capturedValues['module']);
        self::assertNull($capturedValues['action']);
        self::assertNull($capturedValues['parameters']);
    }

    public function testCallUsesSplitSelectorAndParameters(): void
    {
        $captured = new stdClass();
        $captured->values = [];

        $tool = new ApiCall(
            new class ($captured) implements ApiCallQueryServiceInterface {
                public function __construct(private stdClass $captured)
                {
                }

                public function callApi(
                    string $accessMode,
                    ?string $method = null,
                    ?string $module = null,
                    ?string $action = null,
                    ?array $parameters = null,
                ): ApiCallRecord {
                    $this->captured->values = [
                        'accessMode' => $accessMode,
                        'method' => $method,
                        'module' => $module,
                        'action' => $action,
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
            $this->createSystemSettingsStub('full'),
        );

        $actual = $tool->call(
            module: ' UsersManager ',
            action: ' addUser ',
            parameters: ['userLogin' => 'alice'],
        );

        self::assertSame(['success' => true], $actual['result']);
        /** @var array<string, mixed> $capturedValues */
        $capturedValues = $captured->values;
        self::assertSame('full', $capturedValues['accessMode']);
        self::assertNull($capturedValues['method']);
        self::assertSame(' UsersManager ', $capturedValues['module']);
        self::assertSame(' addUser ', $capturedValues['action']);
        self::assertSame(['userLogin' => 'alice'], $capturedValues['parameters']);
    }

    private function createSystemSettingsStub(string $rawApiAccessMode): SystemSettings
    {
        $settings = $this->createMock(SystemSettings::class);
        $settings->method('getRawApiAccessMode')
            ->willReturn($rawApiAccessMode);

        return $settings;
    }
}
