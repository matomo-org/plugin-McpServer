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

    public function testCallApiAppendsUpstreamValidationFailureDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException("Parameter 'userLogin' missing or invalid.")),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Matomo API request failed: Parameter 'userLogin' missing or invalid.");

        $service->callApi($resolvedMethod);
    }

    public function testCallApiAppendsSegmentGuidanceMessageVerbatim(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('SegmentEditor', 'add', 'SegmentEditor.add', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(
                new \RuntimeException('Real time segments are disabled. You need to enable auto archiving.'),
            ),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(
            'Matomo API request failed: Real time segments are disabled. You need to enable auto archiving.',
        );

        $service->callApi($resolvedMethod);
    }

    public function testCallApiAppendsSessionAdjacentGuidanceMessageVerbatim(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord(
            'HeatmapSessionRecording',
            'getRecordedSessions',
            'HeatmapSessionRecording.getRecordedSessions',
            [],
        );
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('Session recording is not enabled for this site.')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(
            'Matomo API request failed: Session recording is not enabled for this site.',
        );

        $service->callApi($resolvedMethod);
    }

    public function testCallApiAppendsValidationDetailWithSqlVerbInProse(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('SitesManager', 'getSite', 'SitesManager.getSite', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('Please select a valid idSite.')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed: Please select a valid idSite.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiAppendsValidationDetailWithDeleteVerbInProse(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'deleteUser', 'UsersManager.deleteUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('Failed to delete user because it does not exist.')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage(
            'Matomo API request failed: Failed to delete user because it does not exist.',
        );

        $service->callApi($resolvedMethod);
    }

    public function testCallApiAppendsValidationDetailWithCallToInProse(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('Call to action required before saving.')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed: Call to action required before saving.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiAppendsValidationDetailWithStrayBackslashNotClassShaped(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('Invalid escape sequence \\d in pattern.')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed: Invalid escape sequence \\d in pattern.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForRawSqlSelectFrom(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(
                new \RuntimeException(
                    "You have an error in your SQL syntax near 'SELECT 1 FROM log_visit WHERE idsite=1'",
                ),
            ),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForSqlInternalDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('SQLSTATE[42S02]: Base table or view not found')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForTokenAuthDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('The token_auth parameter is invalid or missing.')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForBearerDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('Bearer token missing or invalid.')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForSessionDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(
                new \RuntimeException('A valid session token or force_api_session flag is required.'),
            ),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForNestedSegmentValidationDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('SegmentEditor', 'add', 'SegmentEditor.add', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(
                new \RuntimeException(
                    'The specified segment is invalid: Segment term `foo` is not valid for the requested site.',
                ),
            ),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForFilesystemPathDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord(
            'ScheduledReports',
            'generateReport',
            'ScheduledReports.generateReport',
            [],
        );
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(
                new \RuntimeException("The report file wasn't found in /tmp/scheduled/report.pdf"),
            ),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForSanityCheckClassDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('Referrers', 'getReferrerType', 'Referrers.getReferrerType', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('Unexpected DataTable type: Piwik\\DataTable\\Map')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForNamespacedClassTokenDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('The value must be an instance of Piwik\\Foo.')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureForCallToUndefinedMethodDetail(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException('Call to undefined method fooBar')),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    public function testCallApiKeepsGenericFailureWhenNoPreviousFailureDetailExists(): void
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

    private static function gatewayThrowingWrapped(\Throwable $previous): CoreApiCallGatewayInterface
    {
        return new class ($previous) implements CoreApiCallGatewayInterface {
            public function __construct(private \Throwable $previous)
            {
            }

            public function call(string $method, array $parameters): mixed
            {
                throw new CoreApiRequestException('failed', 0, $this->previous);
            }
        };
    }
}
