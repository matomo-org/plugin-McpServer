<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\DataTable;
use Piwik\DataTable\Map;
use Piwik\DataTable\Row;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\CoreApiCallGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\Services\Api\ApiCallQueryService;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiCallQueryServiceTest extends TestCase
{
    public function testCallApiUsesResolvedMethodAndReturnsEnvelope(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('API', 'getMatomoVersion', 'API.getMatomoVersion', []);

        $service = new ApiCallQueryService(
            new class () implements CoreApiCallGatewayInterface {
                public function call(string $method, array $parameters): mixed
                {
                    TestCase::assertSame('API.getMatomoVersion', $method);
                    TestCase::assertSame([], $parameters);

                    return '6.0.0';
                }
            },
        );

        $record = $service->callApi($resolvedMethod);

        self::assertSame('6.0.0', $record->result);
        self::assertSame('API.getMatomoVersion', $record->resolvedMethod->method);
    }

    public function testCallApiPassesParametersAndNormalizesObjects(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('API', 'getSettings', 'API.getSettings', []);
        $service = new ApiCallQueryService(
            new class () implements CoreApiCallGatewayInterface {
                public function call(string $method, array $parameters): mixed
                {
                    TestCase::assertSame(['idSite' => 3], $parameters);

                    return (object) ['site' => (object) ['id' => 3, 'name' => 'Demo']];
                }
            },
        );

        $record = $service->callApi($resolvedMethod, parameters: ['idSite' => 3]);

        self::assertSame(['site' => ['id' => 3, 'name' => 'Demo']], $record->result);
    }

    public function testCallApiNormalizesDataTableResultsViaJsonRenderer(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('Actions', 'getPageUrls', 'Actions.getPageUrls', []);
        $table = new DataTable();
        $table->addRow(new Row([
            Row::COLUMNS => [
                'label' => '/pricing',
                'nb_visits' => 4,
            ],
        ]));

        $service = new ApiCallQueryService(
            new class ($table) implements CoreApiCallGatewayInterface {
                public function __construct(private DataTable $table)
                {
                }

                public function call(string $method, array $parameters): mixed
                {
                    return $this->table;
                }
            },
        );

        $record = $service->callApi($resolvedMethod);

        self::assertSame([
            [
                'label' => '/pricing',
                'nb_visits' => 4,
            ],
        ], $record->result);
    }

    public function testCallApiNormalizesNestedDataTableMapResultsViaJsonRenderer(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('Live', 'getLastVisitDetails', 'Live.getLastVisitDetails', []);
        $first = new DataTable();
        $first->addRow(new Row([
            Row::COLUMNS => [
                'label' => 'first',
                'nb_visits' => 1,
            ],
        ]));

        $second = new DataTable();
        $second->addRow(new Row([
            Row::COLUMNS => [
                'label' => 'second',
                'nb_visits' => 2,
            ],
        ]));

        $map = new Map();
        $map->addTable($first, '2024-01-01');
        $map->addTable($second, '2024-01-02');

        $service = new ApiCallQueryService(
            new class ($map) implements CoreApiCallGatewayInterface {
                public function __construct(private Map $map)
                {
                }

                public function call(string $method, array $parameters): mixed
                {
                    return ['report' => $this->map];
                }
            },
        );

        $record = $service->callApi($resolvedMethod);

        self::assertSame([
            'report' => [
                '2024-01-01' => [
                    [
                        'label' => 'first',
                        'nb_visits' => 1,
                    ],
                ],
                '2024-01-02' => [
                    [
                        'label' => 'second',
                        'nb_visits' => 2,
                    ],
                ],
            ],
        ], $record->result);
    }

    public function testCallApiRejectsReservedParameterKeys(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('API', 'getMatomoVersion', 'API.getMatomoVersion', []);
        $service = new ApiCallQueryService(
            new class () implements CoreApiCallGatewayInterface {
                public function call(string $method, array $parameters): mixed
                {
                    throw new \BadMethodCallException('Should not be called.');
                }
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Unsupported parameters key 'format'.");

        $service->callApi($resolvedMethod, parameters: ['format' => 'json']);
    }

    public function testCallApiMapsAccessDeniedFailures(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('API', 'getMatomoVersion', 'API.getMatomoVersion', []);
        $service = new ApiCallQueryService(
            new class () implements CoreApiCallGatewayInterface {
                public function call(string $method, array $parameters): mixed
                {
                    throw new AccessDeniedLikeException('denied');
                }
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('No access to API method.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiMapsUpstreamFailures(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            new class () implements CoreApiCallGatewayInterface {
                public function call(string $method, array $parameters): mixed
                {
                    throw new CoreApiRequestException('failed');
                }
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiSurfacesSanitizedValidationFailureDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            new class () implements CoreApiCallGatewayInterface {
                public function call(string $method, array $parameters): mixed
                {
                    throw new CoreApiRequestException(
                        'failed',
                        0,
                        new \RuntimeException("Parameter 'userLogin' missing or invalid."),
                    );
                }
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Matomo API request failed: Parameter 'userLogin' missing or invalid.");

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForUnsafeUpstreamDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            new class () implements CoreApiCallGatewayInterface {
                public function call(string $method, array $parameters): mixed
                {
                    throw new CoreApiRequestException(
                        'failed',
                        0,
                        new \RuntimeException('SQLSTATE[42S02]: Base table or view not found'),
                    );
                }
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureWhenNoSafeDetailExists(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            new class () implements CoreApiCallGatewayInterface {
                public function call(string $method, array $parameters): mixed
                {
                    throw new CoreApiRequestException('failed');
                }
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiRejectsInvalidResponse(): void
    {
        $resource = fopen('php://memory', 'rb');
        self::assertIsResource($resource);
        $resolvedMethod = new ApiMethodSummaryRecord('API', 'getMatomoVersion', 'API.getMatomoVersion', []);

        try {
            $service = new ApiCallQueryService(
                new class ($resource) implements CoreApiCallGatewayInterface {
                    private mixed $resource;

                    public function __construct(mixed $resource)
                    {
                        $this->resource = $resource;
                    }

                    public function call(string $method, array $parameters): mixed
                    {
                        return ['stream' => $this->resource];
                    }
                },
            );

            $this->expectException(ToolCallException::class);
            $this->expectExceptionMessage('API response is invalid.');

            $service->callApi($resolvedMethod);
        } finally {
            fclose($resource);
        }
    }
}
