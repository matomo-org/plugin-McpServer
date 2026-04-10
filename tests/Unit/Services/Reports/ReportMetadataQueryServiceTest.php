<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Reports;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Plugins\McpServer\Services\Reports\ReportMetadataQueryService;
use Piwik\Plugins\McpServer\Support\Errors\InfrastructureDataException;

/**
 * @group McpServer
 * @group Plugins
 */
class ReportMetadataQueryServiceTest extends TestCase
{
    public function testNormalizeReportMetadataDataThrowsWhenFieldIsMissing(): void
    {
        $service = $this->makeService();
        $data = $this->makeValidReportMetadataData();
        unset($data['name']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Report metadata item is incomplete (missing 'name').");

        $service->normalizeReportMetadataData($data, 'Report metadata item');
    }

    public function testNormalizeReportMetadataDataRejectsIndexedArrayParameters(): void
    {
        $service = $this->makeService();
        $data = $this->makeValidReportMetadataData();
        $data['parameters'] = ['idGoal'];

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Report metadata item is invalid (field 'parameters').");

        $service->normalizeReportMetadataData($data, 'Report metadata item');
    }

    public function testNormalizeReportMetadataDataReturnsExpectedTypedOutput(): void
    {
        $service = $this->makeService();

        $record = $service->normalizeReportMetadataData(
            $this->makeValidReportMetadataData(),
            'Report metadata item',
        );

        $actual = $record->toArray();
        self::assertSame('Actions_getPageUrls', $actual['uniqueId']);
        self::assertSame('Actions', $actual['module']);
        self::assertSame('getPageUrls', $actual['action']);
        self::assertSame('Page URLs', $actual['name']);
        self::assertSame('Actions', $actual['category']);
        self::assertSame(['idGoal' => '1'], $actual['parameters']);
        self::assertSame('Actions_getPageUrls', $actual['metadata']['uniqueId'] ?? null);
    }

    public function testGetReportMetadataByUniqueIdMapsNoAccessToNotFound(): void
    {
        $service = $this->makeService(
            new class () implements CoreProcessedReportGatewayInterface {
                public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
                {
                    throw new \Piwik\NoAccessException('denied');
                }

                public function getReportMetadata(
                    int $idSite,
                    string $period,
                    \Piwik\Date|string|bool $date,
                    bool $hideMetricsDoc,
                    bool $showSubtableReports,
                ): array {
                    return [];
                }
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report not found.');
        $service->getReportMetadataByUniqueId(1, 'Actions_getPageUrls');
    }

    public function testGetReportMetadataByUniqueIdMapsInfrastructureDataFailureToNotFound(): void
    {
        $service = $this->makeService(
            new class () implements CoreProcessedReportGatewayInterface {
                public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
                {
                    throw new InfrastructureDataException('Report not found.');
                }

                public function getReportMetadata(
                    int $idSite,
                    string $period,
                    \Piwik\Date|string|bool $date,
                    bool $hideMetricsDoc,
                    bool $showSubtableReports,
                ): array {
                    return [];
                }
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report not found.');
        $service->getReportMetadataByUniqueId(1, 'Actions_getPageUrls');
    }

    public function testGetReportMetadataByModuleActionReturnsMatchingReport(): void
    {
        $service = $this->makeService(
            new class ($this->makeValidReportMetadataData()) implements CoreProcessedReportGatewayInterface {
                /**
                 * @param array<string, mixed> $metadata
                 */
                public function __construct(private array $metadata)
                {
                }

                public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
                {
                    return $this->metadata;
                }

                public function getReportMetadata(
                    int $idSite,
                    string $period,
                    \Piwik\Date|string|bool $date,
                    bool $hideMetricsDoc,
                    bool $showSubtableReports,
                ): array {
                    return [$this->metadata];
                }
            },
        );

        $record = $service->getReportMetadataByModuleAction(
            1,
            'Actions',
            'getPageUrls',
            ['idGoal' => '1'],
            'day',
            'today',
        );

        self::assertSame('Actions_getPageUrls', $record->uniqueId);
    }

    public function testGetReportMetadataByModuleActionMapsInfrastructureDataFailureToInvalidMetadata(): void
    {
        $service = $this->makeService(
            new class () implements CoreProcessedReportGatewayInterface {
                public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
                {
                    return [];
                }

                public function getReportMetadata(
                    int $idSite,
                    string $period,
                    \Piwik\Date|string|bool $date,
                    bool $hideMetricsDoc,
                    bool $showSubtableReports,
                ): array {
                    throw new InfrastructureDataException('Report metadata data is invalid.');
                }
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Report metadata data is invalid.');

        $service->getReportMetadataByModuleAction(
            1,
            'Actions',
            'getPageUrls',
            ['idGoal' => '1'],
            'day',
            'today',
        );
    }

    public function testGetReportMetadataByModuleActionNormalizesMonthLast3ToRange(): void
    {
        $metadata = $this->makeValidReportMetadataData();
        $gateway = new class ($metadata) implements CoreProcessedReportGatewayInterface {
            public ?string $capturedPeriod = null;
            public mixed $capturedDate = null;

            /**
             * @param array<string, mixed> $metadata
             */
            public function __construct(private array $metadata)
            {
            }

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
            {
                return $this->metadata;
            }

            public function getReportMetadata(
                int $idSite,
                string $period,
                \Piwik\Date|string|bool $date,
                bool $hideMetricsDoc,
                bool $showSubtableReports,
            ): array {
                $this->capturedPeriod = $period;
                $this->capturedDate = $date;

                return [$this->metadata];
            }
        };
        $service = $this->makeService($gateway);

        $record = $service->getReportMetadataByModuleAction(
            1,
            'Actions',
            'getPageUrls',
            ['idGoal' => '1'],
            'month',
            'last3',
        );

        self::assertSame('Actions_getPageUrls', $record->uniqueId);
        self::assertSame('range', $gateway->capturedPeriod);
        self::assertIsString($gateway->capturedDate);
        self::assertSame(
            PeriodFactory::build('month', 'last3')->getRangeString(),
            $gateway->capturedDate,
        );
    }

    public function testGetReportMetadataByModuleActionAcceptsExplicitRangeDate(): void
    {
        $metadata = $this->makeValidReportMetadataData();
        $gateway = new class ($metadata) implements CoreProcessedReportGatewayInterface {
            public ?string $capturedPeriod = null;
            public mixed $capturedDate = null;

            /**
             * @param array<string, mixed> $metadata
             */
            public function __construct(private array $metadata)
            {
            }

            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
            {
                return $this->metadata;
            }

            public function getReportMetadata(
                int $idSite,
                string $period,
                \Piwik\Date|string|bool $date,
                bool $hideMetricsDoc,
                bool $showSubtableReports,
            ): array {
                $this->capturedPeriod = $period;
                $this->capturedDate = $date;

                return [$this->metadata];
            }
        };
        $service = $this->makeService($gateway);

        $record = $service->getReportMetadataByModuleAction(
            1,
            'Actions',
            'getPageUrls',
            ['idGoal' => '1'],
            'range',
            '2015-01-01,2015-01-31',
        );

        self::assertSame('Actions_getPageUrls', $record->uniqueId);
        self::assertSame('range', $gateway->capturedPeriod);
        self::assertSame('2015-01-01,2015-01-31', $gateway->capturedDate);
    }

    public function testGetReportMetadataByModuleActionRejectsInvalidPeriodDate(): void
    {
        $service = $this->makeService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Invalid period/date parameters.');

        $service->getReportMetadataByModuleAction(
            1,
            'Actions',
            'getPageUrls',
            ['idGoal' => '1'],
            'invalid_period',
            'today',
        );
    }

    private function makeService(
        ?CoreProcessedReportGatewayInterface $gateway = null,
    ): ReportMetadataQueryService {
        $gateway = $gateway ?? new class () implements CoreProcessedReportGatewayInterface {
            public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
            {
                return [];
            }

            public function getReportMetadata(
                int $idSite,
                string $period,
                \Piwik\Date|string|bool $date,
                bool $hideMetricsDoc,
                bool $showSubtableReports,
            ): array {
                return [];
            }
        };

        $translatorRunner = new class () implements TranslatorContextRunnerInterface {
            public function runInEnglish(callable $callback): mixed
            {
                return $callback();
            }
        };

        return new ReportMetadataQueryService(
            $gateway,
            $translatorRunner,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidReportMetadataData(): array
    {
        return [
            'uniqueId' => 'Actions_getPageUrls',
            'module' => 'Actions',
            'action' => 'getPageUrls',
            'name' => 'Page URLs',
            'category' => 'Actions',
            'parameters' => ['idGoal' => '1'],
            'metrics' => ['nb_visits' => 'Visits'],
        ];
    }
}
