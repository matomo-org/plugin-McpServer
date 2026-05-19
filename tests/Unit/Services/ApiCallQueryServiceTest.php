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
        $resolvedMethod = new ApiMethodSummaryRecord('TestPlugin', 'testMethod', 'TestPlugin.testMethod', []);
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

    /**
     * @dataProvider provideUpstreamFailureDetails
     */
    public function testCallApiAppendsUpstreamFailureDetail(string $thrown): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('TestPlugin', 'testMethod', 'TestPlugin.testMethod', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException($thrown)),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed: ' . $thrown . '.');

        $service->callApi($resolvedMethod);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideUpstreamFailureDetails(): iterable
    {
        yield 'parameter validation' => [
            "Parameter 'userLogin' missing or invalid",
        ];
        yield 'segment guidance (real-time disabled)' => [
            'Real time segments are disabled. You need to enable auto archiving',
        ];
        yield 'session recording disabled' => [
            'Session recording is not enabled for this site',
        ];
        yield 'prose contains "select" without sql clause' => [
            'Please select a valid idSite',
        ];
        yield 'prose contains "delete" without sql clause' => [
            'Failed to delete user because it does not exist',
        ];
        yield 'prose contains "call to" without "undefined"' => [
            'Call to action required before saving',
        ];
        yield 'stray backslash, not class-shaped' => [
            'Invalid escape sequence \\d in pattern',
        ];
        yield 'segment not supported' => [
            "The specified segment is invalid: Segment 'ipSearchCountry' is not a supported segment",
        ];
        yield 'segment parser failure' => [
            "The specified segment is invalid: The segment condition 'ipSearchCountry' is not valid",
        ];
        yield 'segment missing value' => [
            "The specified segment is invalid: The segment 'pageUrl=@' has no value specified."
            . ' You can leave this value empty only when you use the operators: != (is not) or == (is)',
        ];
        yield 'SitesManager invalid url' => [
            "The url 'http://example.com/foo/bar' is not a valid URL",
        ];
        yield 'Goals invalid matching string' => [
            "If you choose 'exact match', the matching string must be a URL starting with "
            . "http:// or https://. For example, 'http://www.yourwebsite.com/newsletter/subscribed.html'",
        ];
        yield 'SitesManager site not found' => [
            'website id = 5 not found',
        ];
        yield 'UsersManager user does not exist' => [
            'User does not exist: alice',
        ];
    }

    public function testCallApiNormalizesTrailingPeriodAndWhitespace(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('TestPlugin', 'testMethod', 'TestPlugin.testMethod', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException("  Parameter\t'foo'\n missing.  ")),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Matomo API request failed: Parameter 'foo' missing.");

        $service->callApi($resolvedMethod);
    }

    /**
     * @dataProvider provideSuppressedFailureDetails
     */
    public function testCallApiKeepsGenericFailureForSuppressedDetail(string $thrown): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('TestPlugin', 'testMethod', 'TestPlugin.testMethod', []);
        $service = new ApiCallQueryService(
            self::gatewayThrowingWrapped(new \RuntimeException($thrown)),
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $service->callApi($resolvedMethod);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideSuppressedFailureDetails(): iterable
    {
        yield 'raw sql select-from' => [
            "You have an error in your SQL syntax near 'SELECT 1 FROM log_visit WHERE idsite=1'",
        ];
        yield 'sqlstate internal detail' => [
            'SQLSTATE[42S02]: Base table or view not found',
        ];
        yield 'token_auth' => [
            'The token_auth parameter is invalid or missing.',
        ];
        yield 'bearer token' => [
            'Bearer token missing or invalid.',
        ];
        yield 'session token / force_api_session' => [
            'A valid session token or force_api_session flag is required.',
        ];
        yield 'filesystem path' => [
            "The report file wasn't found in /tmp/scheduled/report.pdf",
        ];
        yield 'sanity-check class detail' => [
            'Unexpected DataTable type: Piwik\\DataTable\\Map',
        ];
        yield 'namespaced class token' => [
            'The value must be an instance of Piwik\\Foo.',
        ];
        yield 'call to undefined method' => [
            'Call to undefined method fooBar',
        ];
    }

    public function testCallApiKeepsGenericFailureWhenNoPreviousFailureDetailExists(): void
    {
        $resolvedMethod = new ApiMethodSummaryRecord('TestPlugin', 'testMethod', 'TestPlugin.testMethod', []);
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
