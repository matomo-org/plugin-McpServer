<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Api;

use PHPUnit\Framework\TestCase;
use Piwik\API\NoDefaultValue;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryQueryRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\Services\Api\ApiMethodSummaryQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class ApiMethodSummaryQueryServiceTest extends TestCase
{
    public function testShouldIncludeMethodMetadataEntryRejectsDocumentationAndDeprecated(): void
    {
        $service = new ApiMethodSummaryQueryService();

        self::assertFalse($service->shouldIncludeMethodMetadataEntry(self::class, '__documentation', [], false));
        self::assertFalse($service->shouldIncludeMethodMetadataEntry(self::class, 'getUsers', [], true));
        self::assertFalse($service->shouldIncludeMethodMetadataEntry(self::class, 'getUsers', 'invalid', false));
        self::assertTrue($service->shouldIncludeMethodMetadataEntry(self::class, 'getUsers', [], false));
    }

    public function testShouldIncludeMethodMetadataEntryRejectsMethodLevelInternalAnnotation(): void
    {
        $service = new ApiMethodSummaryQueryService();

        self::assertFalse($service->shouldIncludeMethodMetadataEntry(
            InternalMethodFixture::class,
            'hiddenMethod',
            [],
            false,
        ));
    }

    public function testShouldIncludeMethodMetadataEntryRejectsClassLevelInternalAnnotation(): void
    {
        $service = new ApiMethodSummaryQueryService();

        self::assertFalse($service->shouldIncludeMethodMetadataEntry(
            InternalClassFixture::class,
            'visibleMethod',
            [],
            false,
        ));
    }

    public function testShouldIncludeMethodMetadataEntryAllowsMissingClassMetadata(): void
    {
        $service = new ApiMethodSummaryQueryService();

        self::assertTrue($service->shouldIncludeMethodMetadataEntry(
            'Piwik\\Plugins\\Missing\\API',
            'getUsers',
            [],
            false,
        ));
    }

    public function testNormalizeParameterMetadataHandlesNoDefaultValueAsRequired(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $parameters = $service->normalizeParameterMetadata([
            'idSite' => [
                'default' => new NoDefaultValue(),
                'type' => 'int',
                'allowsNull' => false,
            ],
        ]);

        self::assertSame([
            [
                'name' => 'idSite',
                'type' => 'int',
                'required' => true,
                'allowsNull' => false,
                'hasDefault' => false,
                'defaultValue' => null,
            ],
        ], $parameters);
    }

    public function testNormalizeParameterMetadataPreservesScalarAndArrayDefaults(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $parameters = $service->normalizeParameterMetadata([
            'period' => [
                'default' => 'day',
                'type' => 'string',
                'allowsNull' => false,
            ],
            'filters' => [
                'default' => ['foo' => 'bar'],
                'type' => 'array',
                'allowsNull' => false,
            ],
        ]);

        self::assertSame('day', $parameters[0]['defaultValue']);
        self::assertSame(['foo' => 'bar'], $parameters[1]['defaultValue']);
        self::assertFalse($parameters[0]['required']);
        self::assertTrue($parameters[0]['hasDefault']);
    }

    public function testNormalizeParameterMetadataTreatsMissingDefaultAsRequired(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $parameters = $service->normalizeParameterMetadata([
            'idSite' => [
                'type' => 'int',
                'allowsNull' => false,
            ],
        ]);

        self::assertSame([
            [
                'name' => 'idSite',
                'type' => 'int',
                'required' => true,
                'allowsNull' => false,
                'hasDefault' => false,
                'defaultValue' => null,
            ],
        ], $parameters);
    }

    public function testNormalizeDefaultParameterValueReturnsNullForNonSerializableObject(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $nonSerializable = fopen('php://memory', 'rb');
        self::assertIsResource($nonSerializable);

        try {
            $value = $service->normalizeDefaultParameterValue((object) ['stream' => $nonSerializable]);
            self::assertNull($value);
        } finally {
            fclose($nonSerializable);
        }
    }

    public function testFilterRecordsAppliesReadAccessMode(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $records = $service->filterRecords(
            $this->createMethodRecords(),
            ApiMethodSummaryQueryRecord::fromInputs('read'),
        );

        self::assertSame(
            ['API.getMatomoVersion', 'SitesManager.isSiteNameUnique', 'UsersManager.getUsers'],
            array_values(array_map(static fn(ApiMethodSummaryRecord $record): string => $record->method, $records)),
        );
    }

    public function testFilterRecordsUsesFullModeForNonHeuristicReadMethod(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $readRecords = $service->filterRecords(
            [new ApiMethodSummaryRecord('UsersManager', 'hasSuperUserAccess', 'UsersManager.hasSuperUserAccess', [])],
            ApiMethodSummaryQueryRecord::fromInputs('read'),
        );
        $fullRecords = $service->filterRecords(
            [new ApiMethodSummaryRecord('UsersManager', 'hasSuperUserAccess', 'UsersManager.hasSuperUserAccess', [])],
            ApiMethodSummaryQueryRecord::fromInputs('full'),
        );

        self::assertSame([], $readRecords);
        self::assertSame(
            ['UsersManager.hasSuperUserAccess'],
            array_values(array_map(static fn(ApiMethodSummaryRecord $record): string => $record->method, $fullRecords)),
        );
    }

    public function testFilterRecordsRemovesBlockedProxyLikeMethodsInFullMode(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $records = $service->filterRecords(
            [
                new ApiMethodSummaryRecord('API', 'getProcessedReport', 'API.getProcessedReport', []),
                new ApiMethodSummaryRecord('API', 'getMetadata', 'API.getMetadata', []),
                new ApiMethodSummaryRecord(
                    'API',
                    'getSuggestedValuesForSegment',
                    'API.getSuggestedValuesForSegment',
                    [],
                ),
                new ApiMethodSummaryRecord(
                    'TreemapVisualization',
                    'getTreemapData',
                    'TreemapVisualization.getTreemapData',
                    [],
                ),
            ],
            ApiMethodSummaryQueryRecord::fromInputs('full'),
        );

        self::assertSame(
            ['API.getSuggestedValuesForSegment'],
            array_values(array_map(static fn(ApiMethodSummaryRecord $record): string => $record->method, $records)),
        );
    }

    public function testFilterRecordsAppliesCaseInsensitiveExactModuleFilter(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $records = $service->filterRecords(
            $this->createMethodRecords(),
            ApiMethodSummaryQueryRecord::fromInputs('full', ' usersmanager '),
        );

        self::assertSame(
            ['UsersManager.addUser', 'UsersManager.getUsers'],
            array_values(array_map(static fn(ApiMethodSummaryRecord $record): string => $record->method, $records)),
        );
    }

    public function testFilterRecordsAppliesCaseInsensitiveSearchAcrossMethodActionAndModule(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $byMethod = $service->filterRecords(
            $this->createMethodRecords(),
            ApiMethodSummaryQueryRecord::fromInputs('full', null, 'getmatomo'),
        );
        self::assertSame(['API.getMatomoVersion'], array_values(array_map(
            static fn(ApiMethodSummaryRecord $record): string => $record->method,
            $byMethod,
        )));

        $byAction = $service->filterRecords(
            $this->createMethodRecords(),
            ApiMethodSummaryQueryRecord::fromInputs('full', null, 'delete'),
        );
        self::assertSame(['SitesManager.deleteSite'], array_values(array_map(
            static fn(ApiMethodSummaryRecord $record): string => $record->method,
            $byAction,
        )));

        $byModule = $service->filterRecords(
            $this->createMethodRecords(),
            ApiMethodSummaryQueryRecord::fromInputs('full', null, 'sitesmanager'),
        );
        self::assertSame(
            ['SitesManager.deleteSite', 'SitesManager.isSiteNameUnique'],
            array_values(array_map(static fn(ApiMethodSummaryRecord $record): string => $record->method, $byModule)),
        );
    }

    public function testFindApiMethodSummaryRecordMatchesMethodCaseInsensitively(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $record = $service->findApiMethodSummaryRecord(
            $this->createMethodRecords(),
            ' usersmanager.getusers ',
        );

        self::assertInstanceOf(ApiMethodSummaryRecord::class, $record);
        self::assertSame('UsersManager.getUsers', $record->method);
    }

    public function testFindApiMethodSummaryRecordMatchesModuleAndActionCaseInsensitively(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $record = $service->findApiMethodSummaryRecord(
            $this->createMethodRecords(),
            null,
            ' usersmanager ',
            ' adduser ',
        );

        self::assertInstanceOf(ApiMethodSummaryRecord::class, $record);
        self::assertSame('UsersManager.addUser', $record->method);
    }

    public function testFindApiMethodSummaryRecordReturnsNullWhenNoMatchExists(): void
    {
        $service = new ApiMethodSummaryQueryService();

        $record = $service->findApiMethodSummaryRecord(
            $this->createMethodRecords(),
            'API.missingMethod',
        );

        self::assertNull($record);
    }

    /**
     * @return array<int, ApiMethodSummaryRecord>
     */
    private function createMethodRecords(): array
    {
        return [
            new ApiMethodSummaryRecord('API', 'getMatomoVersion', 'API.getMatomoVersion', []),
            new ApiMethodSummaryRecord('SitesManager', 'deleteSite', 'SitesManager.deleteSite', []),
            new ApiMethodSummaryRecord('SitesManager', 'isSiteNameUnique', 'SitesManager.isSiteNameUnique', []),
            new ApiMethodSummaryRecord('UsersManager', 'addUser', 'UsersManager.addUser', []),
            new ApiMethodSummaryRecord('UsersManager', 'getUsers', 'UsersManager.getUsers', []),
        ];
    }
}

class InternalMethodFixture
{
    /**
     * @internal
     */
    public function hiddenMethod(): void
    {
    }
}

/**
 * @internal
 */
class InternalClassFixture
{
    public function visibleMethod(): void
    {
    }
}
