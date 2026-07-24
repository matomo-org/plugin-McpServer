<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Reports;

use Piwik\Access;
use Piwik\DataTable;
use Piwik\DataTable\DataTableInterface;
use Piwik\DataTable\Map;
use Piwik\DataTable\Renderer\Json;
use Piwik\NoAccessException;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreApiModuleGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\StrictSegmentPolicyServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportProcessedRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;
use Piwik\Plugins\McpServer\Support\Errors\InfrastructureDataException;
use Piwik\Plugins\McpServer\Support\Errors\NoAccessLikeErrorDetector;
use Piwik\Plugins\McpServer\Support\Errors\ToolErrorMapper;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\McpServer\Support\Reports\GoalMetricsMode;
use Piwik\Plugins\SegmentEditor\UnprocessedSegmentException;

final class ReportProcessedQueryService implements ReportProcessedQueryServiceInterface
{
    private const FILTER_LIMIT_MAX = 250;
    private const GOAL_COLUMNS_MODE_KEY = 'filter_update_columns_when_show_all_goals';
    private const GOAL_COLUMNS_PROCESS_GOALS_KEY = 'filter_show_goal_columns_process_goals';
    private const SUBTABLE_REPORT_REQUIRES_ID_SUBTABLE_MESSAGE =
        'Selected subtable report requires idSubtable. '
        . 'First query the parent report and use a returned row subtable ID.';
    private const STRICT_SEGMENT_ERROR_MESSAGE =
        'Segment is not allowed in this Matomo configuration: only existing pre-archived segments can be used. '
        . 'Use matomo_segment_list to select a saved segment definition.';

    /** @var list<string> */
    private const STRICT_SEGMENT_RESTRICTION_MESSAGE_FRAGMENTS = [
        'has not yet been created in the segment editor',
        'report data has not been pre-processed',
        'has not yet been processed by the system',
        'not currently configured to process segmented reports in api requests',
    ];

    private const INVALID_SEGMENT_SYNTAX_MESSAGE =
        'Segment expression could not be parsed. Write each condition as field then operator then '
        . 'value (e.g. countryCode==de), combine them with ; for AND or , for OR, and use one of the '
        . 'operators ==, !=, <=, >=, <, >, =@ (contains), !@ (does not contain), =^ (starts with), '
        . '=$ (ends with). Use matomo_segment_list to see the available segment fields.';

    private const UNKNOWN_SEGMENT_FIELD_MESSAGE =
        'Segment expression names a field this Matomo does not provide. '
        . 'Use matomo_segment_list to see the available segment fields.';

    /**
     * Core reports both of these as a plain exception, so they are recognised by message fragment,
     * the same way {@see STRICT_SEGMENT_RESTRICTION_MESSAGE_FRAGMENTS} is. Both are only consulted
     * when the request actually carried a segment, so an unrelated failure cannot be relabelled.
     *
     * @var list<string>
     */
    private const INVALID_SEGMENT_SYNTAX_MESSAGE_FRAGMENTS = [
        'segment condition',
        'please specify a valid segment',
        'has no value specified',
        'for your segment',
    ];

    /** @var list<string> */
    private const UNKNOWN_SEGMENT_FIELD_MESSAGE_FRAGMENTS = [
        'is not a supported segment',
    ];

    /** @var array<string, true> */
    private const DANGEROUS_API_PARAMETER_KEYS = [
        'method' => true,
        'module' => true,
        'format' => true,
        'serialize' => true,
        'token_auth' => true,
        'force_api_session' => true,
        'translateColumnNames' => true,
    ];

    /** @var callable|null */
    private $processedReportCaller;

    public function __construct(
        private ReportMetadataQueryServiceInterface $metadataQueryService,
        private CoreApiModuleGatewayInterface $coreApiModuleGateway,
        private TranslatorContextRunnerInterface $translatorContextRunner,
        private StrictSegmentPolicyServiceInterface $strictSegmentPolicy,
        ?callable $processedReportCaller = null,
    ) {
        $this->processedReportCaller = $processedReportCaller;
    }

    /**
     * @param array<string, mixed>|null $apiParameters
     * @param list<int|string>|null $goalMetricsProcessGoals
     */
    public function getProcessedReport(
        int $idSite,
        string $period,
        string $date,
        ?string $reportUniqueId,
        ?string $apiModule,
        ?string $apiAction,
        ?array $apiParameters,
        ?string $goalMetricsMode,
        ?array $goalMetricsProcessGoals,
        ?string $segment,
        int|string|null $idGoal,
        ?int $idDimension,
        ?int $idSubtable,
        int $filterLimit,
        int $filterOffset,
    ): ReportProcessedRecord {
        $this->validatePeriodAndDate($period, $date);
        $normalizedApiParameters = $this->normalizeApiParameters($apiParameters);
        [$genericSafeParameters, $reportSpecificParameters] = $this->splitSafeAndReportSpecificApiParameters(
            $normalizedApiParameters,
        );

        if ($reportUniqueId !== null) {
            $reportMetadata = $this->metadataQueryService->getReportMetadataByUniqueId($idSite, $reportUniqueId);
            if ($reportSpecificParameters !== []) {
                throw new McpToolCallException(
                    'Invalid apiParameters for reportUniqueId lookup. '
                    . 'Only safe filter/sort/columns/expanded/flat/label/compare* parameters are allowed.',
                );
            }
        } else {
            $reportMetadata = $this->resolveMetadataByModuleAction(
                $idSite,
                (string) $apiModule,
                (string) $apiAction,
                $reportSpecificParameters,
                $idGoal,
                $idDimension,
                $period,
                $date,
            );
        }

        $this->validateSubtableReportSelection($reportMetadata, $idSubtable);

        $reportUsesIdGoalSelector = $this->reportUsesIdGoalSelector($reportMetadata);
        $apiParametersForCall = $reportMetadata->parameters;
        [$genericRequestParameters, $goalRequestParameters] = $this->extractRequestParameters(
            $genericSafeParameters,
            $goalMetricsMode,
            $goalMetricsProcessGoals,
            $idGoal,
            $reportUsesIdGoalSelector,
        );
        $requestParameters = array_merge($genericRequestParameters, $goalRequestParameters);
        $resolvedApiParametersForResponse = array_merge($apiParametersForCall, $requestParameters);
        $idGoalForCoreCall = $reportUsesIdGoalSelector ? $idGoal : null;

        $effectiveFilterLimit = $this->normalizeFilterLimit($filterLimit);
        $effectiveFilterOffset = $this->normalizeFilterOffset($filterOffset);

        $processed = $this->callProcessedReport(
            idSite: $idSite,
            period: $period,
            date: $date,
            reportMetadata: $reportMetadata,
            segment: $segment,
            apiParameters: $apiParametersForCall,
            requestParameters: $requestParameters,
            idGoal: $idGoalForCoreCall,
            idDimension: $idDimension,
            idSubtable: $idSubtable,
            filterLimit: $effectiveFilterLimit,
            filterOffset: $effectiveFilterOffset,
        );

        [$returnedRows, $totalRows, $hasMore] = $this->derivePaginationFromProcessedReport(
            $processed,
            $effectiveFilterOffset,
        );
        $normalizedReport = $this->normalizeProcessedReportPayload($processed);
        $this->throwStrictSegmentGuidanceForEmptySegmentedReportIfNeeded(
            $normalizedReport,
            $returnedRows,
            $idSite,
            $period,
            $date,
            $segment,
        );

        return new ReportProcessedRecord(
            report: $normalizedReport,
            filterLimit: $effectiveFilterLimit,
            filterOffset: $effectiveFilterOffset,
            returnedRows: $returnedRows,
            totalRows: $totalRows,
            hasMore: $hasMore,
            uniqueId: $reportMetadata->uniqueId,
            apiModule: $reportMetadata->module,
            apiAction: $reportMetadata->action,
            apiParameters: $resolvedApiParametersForResponse,
        );
    }

    /**
     * @param array<string, mixed> $reportSpecificParameters
     */
    private function resolveMetadataByModuleAction(
        int $idSite,
        string $apiModule,
        string $apiAction,
        array $reportSpecificParameters,
        int|string|null $idGoal,
        ?int $idDimension,
        string $period,
        string $date,
    ): ReportMetadataRecord {
        try {
            return $this->metadataQueryService->getReportMetadataByModuleAction(
                $idSite,
                $apiModule,
                $apiAction,
                $reportSpecificParameters,
                $period,
                $date,
            );
        } catch (McpToolCallException $e) {
            if (!$this->isReportNotFoundError($e)) {
                throw $e;
            }

            $fallbackParameters = $this->buildFallbackMetadataLookupParameters(
                $reportSpecificParameters,
                $idGoal,
                $idDimension,
            );
            if ($fallbackParameters === null) {
                throw $e;
            }

            return $this->metadataQueryService->getReportMetadataByModuleAction(
                $idSite,
                $apiModule,
                $apiAction,
                $fallbackParameters,
                $period,
                $date,
            );
        }
    }

    private function isReportNotFoundError(McpToolCallException $e): bool
    {
        return trim($e->getMessage()) === 'Report not found.';
    }

    /**
     * @param array<string, mixed> $reportSpecificParameters
     * @return array<string, mixed>|null
     */
    private function buildFallbackMetadataLookupParameters(
        array $reportSpecificParameters,
        int|string|null $idGoal,
        ?int $idDimension,
    ): ?array {
        $fallback = $reportSpecificParameters;
        $changed = false;

        if (
            ($idGoal !== null && $idGoal !== '')
            && !array_key_exists('idGoal', $fallback)
        ) {
            $fallback['idGoal'] = $idGoal;
            $changed = true;
        }

        if (
            $idDimension !== null
            && !array_key_exists('idDimension', $fallback)
        ) {
            $fallback['idDimension'] = $idDimension;
            $changed = true;
        }

        return $changed ? $fallback : null;
    }

    /**
     * @param array<string, mixed>|null $apiParameters
     * @return array<string, mixed>
     */
    private function normalizeApiParameters(?array $apiParameters): array
    {
        if ($apiParameters === null) {
            return [];
        }

        foreach ($apiParameters as $key => $value) {
            if (isset(self::DANGEROUS_API_PARAMETER_KEYS[$key])) {
                throw new McpToolCallException("Unsupported apiParameters key '{$key}'.");
            }
            if (
                $key === self::GOAL_COLUMNS_MODE_KEY
                || $key === self::GOAL_COLUMNS_PROCESS_GOALS_KEY
            ) {
                throw new McpToolCallException(
                    "Use top-level goal parameters instead of apiParameters key '{$key}'.",
                );
            }

            if (!is_scalar($value) && !is_array($value) && $value !== null) {
                throw new McpToolCallException("Invalid apiParameters value for key '{$key}'.");
            }
        }

        return $apiParameters;
    }

    /**
     * @param array<string, mixed> $genericSafeParameters
     * @param list<mixed>|null $goalMetricsProcessGoals
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function extractRequestParameters(
        array $genericSafeParameters,
        ?string $goalMetricsMode,
        ?array $goalMetricsProcessGoals,
        int|string|null $idGoal,
        bool $reportUsesIdGoalSelector,
    ): array {
        $requestParameters = $genericSafeParameters;
        /** @var array<string, string> $goalRequestParameters */
        $goalRequestParameters = [];

        $isSpecificGoalMode = $goalMetricsMode === GoalMetricsMode::SPECIFIC_GOAL->value;
        if ($isSpecificGoalMode && $reportUsesIdGoalSelector) {
            return [$requestParameters, $goalRequestParameters];
        }

        $normalizedSpecificGoal = null;
        if ($goalMetricsMode !== null) {
            $normalizedGoalColumnsMode = $this->normalizeGoalColumnsMode($goalMetricsMode, $idGoal);
            if ($isSpecificGoalMode) {
                $normalizedSpecificGoal = $normalizedGoalColumnsMode;
            }
            $goalRequestParameters[self::GOAL_COLUMNS_MODE_KEY] = $normalizedGoalColumnsMode;
        }

        if ($goalMetricsProcessGoals === null && $isSpecificGoalMode) {
            $goalMetricsProcessGoals = [
                $normalizedSpecificGoal ?? $this->normalizeGoalColumnsMode($goalMetricsMode, $idGoal),
            ];
        }

        if ($goalMetricsProcessGoals !== null) {
            $goalRequestParameters[self::GOAL_COLUMNS_PROCESS_GOALS_KEY] = implode(
                ',',
                $this->normalizeGoalColumnsProcessGoals($goalMetricsProcessGoals),
            );
        }

        return [$requestParameters, $goalRequestParameters];
    }

    private function normalizeGoalColumnsMode(string $goalMetricsMode, int|string|null $idGoal): string
    {
        return GoalMetricsMode::fromInput($goalMetricsMode)->toCoreFilterValue($idGoal);
    }

    /**
     * @param list<mixed> $goalMetricsProcessGoals
     * @return list<string>
     */
    private function normalizeGoalColumnsProcessGoals(array $goalMetricsProcessGoals): array
    {
        $normalized = [];
        foreach ($goalMetricsProcessGoals as $value) {
            if (!is_int($value) && !is_string($value)) {
                throw new McpToolCallException(
                    'Invalid goalMetricsProcessGoals value: expected int or int-like string.',
                );
            }

            $stringValue = trim((string) $value);
            if (
                $stringValue === ''
                || (
                    preg_match('/^[1-9][0-9]*$/', $stringValue) !== 1
                    && !GoalMetricsMode::isCoreEcommerceGoalId($stringValue)
                )
            ) {
                throw new McpToolCallException(
                    "Invalid goalMetricsProcessGoals value '{$stringValue}': expected positive goal ID or "
                    . 'core ecommerce goal ID (ecommerceOrder, ecommerceAbandonedCart).',
                );
            }

            $normalized[] = $stringValue;
        }

        if ($normalized === []) {
            throw new McpToolCallException(
                'Invalid goalMetricsProcessGoals value: at least one goal ID is required.',
            );
        }

        return array_values(array_unique($normalized));
    }

    private function validateSubtableReportSelection(
        ReportMetadataRecord $reportMetadata,
        ?int $idSubtable,
    ): void {
        if (!$reportMetadata->isSubtableReport) {
            return;
        }

        if ($idSubtable !== null && $idSubtable > 0) {
            return;
        }

        throw new McpToolCallException(self::SUBTABLE_REPORT_REQUIRES_ID_SUBTABLE_MESSAGE);
    }

    private function reportUsesIdGoalSelector(ReportMetadataRecord $reportMetadata): bool
    {
        return array_key_exists('idGoal', $reportMetadata->parameters);
    }

    /**
     * @param array<string, mixed> $apiParameters
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function splitSafeAndReportSpecificApiParameters(array $apiParameters): array
    {
        $genericSafe = [];
        $reportSpecific = [];

        foreach ($apiParameters as $key => $value) {
            if ($this->isGenericSafeApiParameterKey($key)) {
                $genericSafe[$key] = $value;
                continue;
            }

            $reportSpecific[$key] = $value;
        }

        return [$genericSafe, $reportSpecific];
    }

    private function isGenericSafeApiParameterKey(string $key): bool
    {
        if (
            $key === 'expanded'
            || $key === 'flat'
            || $key === 'label'
            || $key === 'columns'
        ) {
            return true;
        }

        if (str_starts_with($key, 'compare')) {
            return true;
        }

        if (str_starts_with($key, 'filter_')) {
            return true;
        }

        if (str_starts_with($key, 'sort')) {
            return true;
        }

        return false;
    }

    private function normalizeFilterLimit(int $filterLimit): int
    {
        if ($filterLimit < 1) {
            return 1;
        }

        return min($filterLimit, self::FILTER_LIMIT_MAX);
    }

    private function normalizeFilterOffset(int $filterOffset): int
    {
        return max($filterOffset, 0);
    }

    private function validatePeriodAndDate(string $period, string $date): void
    {
        try {
            PeriodFactory::build($period, $date);
        } catch (\Throwable) {
            throw new McpToolCallException('Invalid period/date parameters.');
        }
    }

    /**
     * @param array<string, mixed> $apiParameters
     * @param array<string, mixed> $requestParameters
     * @return array<string, mixed>
     */
    private function callProcessedReport(
        int $idSite,
        string $period,
        string $date,
        ReportMetadataRecord $reportMetadata,
        ?string $segment,
        array $apiParameters,
        array $requestParameters,
        int|string|null $idGoal,
        ?int $idDimension,
        ?int $idSubtable,
        int $filterLimit,
        int $filterOffset,
    ): array {
        if ($this->processedReportCaller === null) {
            try {
                Access::getInstance()->checkUserHasViewAccess($idSite);
            } catch (NoAccessException) {
                throw new McpToolCallException('Report not found.');
            }
        }

        $resolvedRequestParameters = [
            'idSite' => (string) $idSite,
            'period' => $period,
            'date' => $date,
        ];
        $resolvedRequestParameters = array_merge($resolvedRequestParameters, $requestParameters);
        $resolvedRequestParameters['idSite'] = (string) $idSite;
        $resolvedRequestParameters['period'] = $period;
        $resolvedRequestParameters['date'] = $date;
        $resolvedRequestParameters['filter_limit'] = (string) $filterLimit;
        $resolvedRequestParameters['filter_offset'] = (string) $filterOffset;

        try {
            $processed = $this->translatorContextRunner->runInEnglish(function () use (
                $idSite,
                $period,
                $date,
                $reportMetadata,
                $segment,
                $apiParameters,
                $resolvedRequestParameters,
                $idGoal,
                $idDimension,
                $idSubtable
            ) {
                return $this->invokeProcessedReport(
                    $idSite,
                    $period,
                    $date,
                    $reportMetadata->module,
                    $reportMetadata->action,
                    $segment,
                    $apiParameters,
                    $resolvedRequestParameters,
                    $idGoal,
                    $idDimension,
                    $idSubtable,
                );
            });
        } catch (NoAccessException) {
            throw new McpToolCallException('Report not found.');
        } catch (AccessDeniedLikeException) {
            throw new McpToolCallException('Report not found.');
        } catch (InfrastructureDataException) {
            throw new McpToolCallException('Report data is invalid.');
        } catch (CoreApiRequestException $e) {
            $rootCause = $e->getPrevious() ?? $e;
            $this->throwSegmentExpressionGuidanceIfNeeded($segment, $rootCause);
            $shouldMapToStrictSegmentGuidance = false;
            if (
                $segment !== null
                && trim($segment) !== ''
                && $this->isStrictSegmentRestrictionLikeFailure($rootCause)
            ) {
                try {
                    $shouldMapToStrictSegmentGuidance = $this->strictSegmentPolicy->shouldMapToStrictSegmentGuidance(
                        $idSite,
                        $period,
                        $date,
                        $segment,
                    );
                } catch (\Throwable) {
                    $shouldMapToStrictSegmentGuidance = false;
                }
            }

            if ($shouldMapToStrictSegmentGuidance) {
                throw new McpToolCallException(self::STRICT_SEGMENT_ERROR_MESSAGE);
            }

            ToolErrorMapper::throwDetailFailure(
                $rootCause,
                'Report not found.',
                'Report retrieval failed.',
                static fn(\Throwable $error): bool => NoAccessLikeErrorDetector::isDetected($error)
                    && ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()
            );
        } catch (McpToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->throwSegmentExpressionGuidanceIfNeeded($segment, $e);
            ToolErrorMapper::throwDetailFailure(
                $e,
                'Report not found.',
                'Report retrieval failed.',
                static fn(\Throwable $error): bool => NoAccessLikeErrorDetector::isDetected($error)
                    && ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()
            );
        }

        return ToolDataNormalizer::requireStringKeyedArray($processed, 'Report data is invalid.');
    }

    /**
     * @param array<string, mixed> $processed
     * @return array{0: int, 1: int, 2: bool}
     */
    private function derivePaginationFromProcessedReport(array $processed, int $filterOffset): array
    {
        $reportData = $processed['reportData'] ?? null;
        if ($reportData instanceof DataTable) {
            return $this->derivePaginationFromDataTable($reportData, $filterOffset);
        }

        if ($reportData instanceof Map) {
            return $this->derivePaginationFromDataTableMap($reportData, $filterOffset);
        }

        throw new McpToolCallException('Report data is invalid.');
    }

    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    private function derivePaginationFromDataTable(DataTable $reportData, int $filterOffset): array
    {
        $returnedRows = $reportData->getRowsCount();
        $totalRowsBeforeLimit = $reportData->getMetadata(DataTable::TOTAL_ROWS_BEFORE_LIMIT_METADATA_NAME);
        if (!is_int($totalRowsBeforeLimit) || $totalRowsBeforeLimit < 0) {
            $totalRowsBeforeLimit = $returnedRows;
        }

        $hasMore = ($filterOffset + $returnedRows) < $totalRowsBeforeLimit;

        return [$returnedRows, $totalRowsBeforeLimit, $hasMore];
    }

    /**
     * @return array{0: int, 1: int, 2: bool}
     */
    private function derivePaginationFromDataTableMap(Map $reportData, int $filterOffset): array
    {
        $tables = array_values($reportData->getDataTables());
        if ($tables === []) {
            throw new McpToolCallException('Report data is invalid.');
        }

        $returnedRows = 0;
        $totalRows = 0;
        $hasMore = false;
        foreach ($tables as $table) {
            if (!$table instanceof DataTable) {
                throw new McpToolCallException('Report data is invalid.');
            }

            [$tableReturnedRows, $tableTotalRows, $tableHasMore] = $this->derivePaginationFromDataTable(
                $table,
                $filterOffset,
            );
            $returnedRows = max($returnedRows, $tableReturnedRows);
            $totalRows = max($totalRows, $tableTotalRows);
            $hasMore = $hasMore || $tableHasMore;
        }

        return [$returnedRows, $totalRows, $hasMore];
    }

    /**
     * @param array<string, mixed> $processed
     * @return array<string, mixed>
     */
    private function normalizeProcessedReportPayload(array $processed): array
    {
        $normalized = [];
        foreach ($processed as $key => $value) {
            $normalized[$key] = $this->normalizeMixedValue($value);
        }

        return $normalized;
    }

    private function normalizeMixedValue(mixed $value): mixed
    {
        if ($value instanceof DataTableInterface) {
            $renderer = new Json();
            $renderer->setTable($value);
            $json = $renderer->render();
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeMixedValue($item);
            }

            return $value;
        }

        if (is_object($value)) {
            $json = json_encode($value, JSON_THROW_ON_ERROR);
            return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        return $value;
    }
    /**
     * @param array<string, mixed> $report
     */
    private function throwStrictSegmentGuidanceForEmptySegmentedReportIfNeeded(
        array $report,
        int $returnedRows,
        int $idSite,
        string $period,
        string $date,
        ?string $segment,
    ): void {
        if (trim((string) $segment) === '') {
            return;
        }

        if (!$this->isEmptyTabularReportResult($report, $returnedRows)) {
            return;
        }

        try {
            $shouldMapToStrictSegmentGuidance = $this->strictSegmentPolicy->shouldMapToStrictSegmentGuidance(
                $idSite,
                $period,
                $date,
                $segment,
            );
        } catch (\Throwable) {
            $shouldMapToStrictSegmentGuidance = false;
        }

        if ($shouldMapToStrictSegmentGuidance) {
            throw new McpToolCallException(self::STRICT_SEGMENT_ERROR_MESSAGE);
        }
    }

    /**
     * @param array<string, mixed> $report
     */
    private function isEmptyTabularReportResult(array $report, int $returnedRows): bool
    {
        if ($returnedRows > 0) {
            return false;
        }

        $reportData = $report['reportData'] ?? null;
        if (!is_array($reportData)) {
            return false;
        }

        return array_is_list($reportData);
    }

    /**
     * @param array<string, mixed> $apiParameters
     * @param array<string, mixed> $requestParameters
     * @return array<string, mixed>
     */
    private function invokeProcessedReport(
        int $idSite,
        string $period,
        string $date,
        string $apiModule,
        string $apiAction,
        ?string $segment,
        array $apiParameters,
        array $requestParameters,
        int|string|null $idGoal,
        ?int $idDimension,
        ?int $idSubtable,
    ): array {
        if ($this->processedReportCaller !== null) {
            $processed = ($this->processedReportCaller)(
                $idSite,
                $period,
                $date,
                $apiModule,
                $apiAction,
                $segment,
                $apiParameters,
                $requestParameters,
                $idGoal,
                $idDimension,
                $idSubtable,
            );

            return ToolDataNormalizer::requireStringKeyedArray($processed, 'Report data is invalid.');
        }

        return $this->coreApiModuleGateway->getProcessedReport(
            $idSite,
            $period,
            $date,
            $apiModule,
            $apiAction,
            $segment,
            $apiParameters,
            $requestParameters,
            $idGoal,
            $idDimension,
            $idSubtable,
        );
    }

    /**
     * Turns a Core segment-parsing failure into guidance the caller can act on.
     *
     * A malformed segment otherwise reaches the generic mapper and comes back as
     * `Report retrieval failed.`, which says nothing about the one argument at fault. That matters
     * more since a `segment` nested in `apiParameters` is lifted to this argument at intake, so
     * expressions that never used to reach Core now do.
     *
     * The message states the expected form and never repeats the supplied expression, which may
     * carry personal data - the same rule intake argument issues follow.
     */
    private function throwSegmentExpressionGuidanceIfNeeded(?string $segment, \Throwable $rootCause): void
    {
        if ($segment === null || trim($segment) === '') {
            return;
        }

        if ($this->matchesAnyMessageFragment($rootCause, self::UNKNOWN_SEGMENT_FIELD_MESSAGE_FRAGMENTS)) {
            throw new McpToolCallException(self::UNKNOWN_SEGMENT_FIELD_MESSAGE);
        }

        if ($this->matchesAnyMessageFragment($rootCause, self::INVALID_SEGMENT_SYNTAX_MESSAGE_FRAGMENTS)) {
            throw new McpToolCallException(self::INVALID_SEGMENT_SYNTAX_MESSAGE);
        }
    }

    /**
     * @param list<string> $fragments
     */
    private function matchesAnyMessageFragment(\Throwable $e, array $fragments): bool
    {
        $current = $e;

        do {
            $message = strtolower(trim($current->getMessage()));
            if ($message !== '') {
                foreach ($fragments as $fragment) {
                    if (str_contains($message, $fragment)) {
                        return true;
                    }
                }
            }

            $current = $current->getPrevious();
        } while ($current !== null);

        return false;
    }

    private function isStrictSegmentRestrictionLikeFailure(\Throwable $e): bool
    {
        $current = $e;

        do {
            if ($current instanceof UnprocessedSegmentException) {
                return true;
            }

            $current = $current->getPrevious();
        } while ($current !== null);

        return $this->matchesAnyMessageFragment($e, self::STRICT_SEGMENT_RESTRICTION_MESSAGE_FRAGMENTS);
    }
}
