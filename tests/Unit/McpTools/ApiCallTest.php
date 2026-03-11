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
use Piwik\Config;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiCallQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiCallRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\McpTools\ApiCall;
use stdClass;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiCallTest extends TestCase
{
    /** @var array<string, mixed>|null */
    private ?array $originalMcpServerConfig = null;

    public function setUp(): void
    {
        parent::setUp();

        $originalConfig = Config::getInstance()->McpServer ?? null;
        $this->originalMcpServerConfig = is_array($originalConfig) ? $originalConfig : null;
    }

    public function tearDown(): void
    {
        Config::getInstance()->McpServer = $this->originalMcpServerConfig;

        parent::tearDown();
    }

    public function testCallUsesMethodSelector(): void
    {
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'read'];
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
                        new ApiMethodSummaryRecord('API', 'getMatomoVersion', 'API.getMatomoVersion', []),
                    );
                }
            },
        );

        $actual = $tool->call(method: ' API.getMatomoVersion ');

        self::assertSame([
            'result' => '6.0.0',
            'resolvedMethod' => [
                'module' => 'API',
                'action' => 'getMatomoVersion',
                'method' => 'API.getMatomoVersion',
                'parameters' => [],
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
        Config::getInstance()->McpServer = ['raw_api_access_mode' => 'full'];
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
                        new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []),
                    );
                }
            },
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
}
