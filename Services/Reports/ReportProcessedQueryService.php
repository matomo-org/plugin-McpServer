<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Reports;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Access;
use Piwik\DataTable\DataTableInterface;
use Piwik\DataTable\Renderer\Json;
use Piwik\NoAccessException;
use Piwik\Period\Factory as PeriodFactory;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreApiModuleGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\StrictSegmentPolicyServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportProcessedRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;
use Piwik\Plugins\McpServer\Support\Errors\InfrastructureDataException;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\McpServer\Support\RequestScope\GetRequestScopeMutatorInterface;
use Piwik\Plugins\McpServer\Support\Reports\GoalMetricsMode;
use Piwik\Plugins\SegmentEditor\UnprocessedSegmentException;

final class ReportProcessedQueryService implements ReportProcessedQueryServiceInterface
{
    private const FILTER_LIMIT_MAX = 250;
    private const GOAL_COLUMNS_MODE_KEY = 'filter_update_columns_when_show_all_goals';
    private const GOAL_COLUMNS_PROCESS_GOALS_KEY = 'filter_show_goal_columns_process_goals';
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
        private GetRequestScopeMutatorInterface $getRequestScopeMutator,
        private CoreApiModuleGatewayInterface $coreApiModuleGateway,
        private TranslatorContextRunnerInterface $translatorContextRunner,
        private StrictSegmentPolicyServiceInterface $strictSegmentPolicy,
        ?callable $processedReportCaller = null
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
        int $filterOffset
    ): ReportProcessedRecord {
        $this->validatePeriodAndDate($period, $date);
        $normalizedApiParameters = $this->normalizeApiParameters($apiParameters);
        [$genericSafeParameters, $reportSpecificParameters] = $this->splitSafeAndReportSpecificApiParameters(
            $normalizedApiParameters
        );

        if ($reportUniqueId !== null) {
            $reportMetadata = $this->metadataQueryService->getReportMetadataByUniqueId($idSite, $reportUniqueId);
            if ($reportSpecificParameters !== []) {
                throw new ToolCallException(
                    'Invalid apiParameters for reportUniqueId lookup. '
                    . 'Only safe filter/sort/columns/expanded/flat/label/compare* parameters are allowed.'
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
                $date
            );
        }

        $reportUsesIdGoalSelector = $this->reportUsesIdGoalSelector($reportMetadata);
        $apiParametersForCall = $reportMetadata->parameters;
        [$genericRequestParameters, $goalRequestParameters] = $this->extractRequestScopeParameters(
            $genericSafeParameters,
            $goalMetricsMode,
            $goalMetricsProcessGoals,
            $idGoal,
            $reportUsesIdGoalSelector
        );
        $requestScopeParameters = array_merge($genericRequestParameters, $goalRequestParameters);
        $resolvedApiParametersForResponse = array_merge($apiParametersForCall, $requestScopeParameters);
        $idGoalForCoreCall = $reportUsesIdGoalSelector ? $idGoal : null;

        $requestedFilterLimit = $this->normalizeFilterLimit($filterLimit);
        $effectiveFilterLimit = $requestedFilterLimit + 1;
        $effectiveFilterOffset = $this->normalizeFilterOffset($filterOffset);

        $processed = $this->callProcessedReport(
            idSite: $idSite,
            period: $period,
            date: $date,
            reportMetadata: $reportMetadata,
            segment: $segment,
            apiParameters: $apiParametersForCall,
            requestScopeParameters: $requestScopeParameters,
            idGoal: $idGoalForCoreCall,
            idDimension: $idDimension,
            idSubtable: $idSubtable,
            filterLimit: $effectiveFilterLimit,
            filterOffset: $effectiveFilterOffset
        );

        $normalizedReport = $this->normalizeProcessedReportPayload($processed);
        [$normalizedReport, $returnedRows, $hasMore] = $this->trimToRequestedLimit(
            $normalizedReport,
            $requestedFilterLimit
        );
        $this->throwStrictSegmentGuidanceForEmptySegmentedReportIfNeeded(
            $normalizedReport,
            $returnedRows,
            $idSite,
            $period,
            $date,
            $segment
        );

        return new ReportProcessedRecord(
            report: $normalizedReport,
            filterLimit: $requestedFilterLimit,
            filterOffset: $effectiveFilterOffset,
            returnedRows: $returnedRows,
            hasMore: $hasMore,
            uniqueId: $reportMetadata->uniqueId,
            apiModule: $reportMetadata->module,
            apiAction: $reportMetadata->action,
            apiParameters: $resolvedApiParametersForResponse
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
        string $date
    ): ReportMetadataRecord {
        try {
            return $this->metadataQueryService->getReportMetadataByModuleAction(
                $idSite,
                $apiModule,
                $apiAction,
                $reportSpecificParameters,
                $period,
                $date
            );
        } catch (ToolCallException $e) {
            if (!$this->isReportNotFoundError($e)) {
                throw $e;
            }

            $fallbackParameters = $this->buildFallbackMetadataLookupParameters(
                $reportSpecificParameters,
                $idGoal,
                $idDimension
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
                $date
            );
        }
    }

    private function isReportNotFoundError(ToolCallException $e): bool
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
        ?int $idDimension
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
                throw new ToolCallException("Unsupported apiParameters key '{$key}'.");
            }
            if (
                $key === self::GOAL_COLUMNS_MODE_KEY
                || $key === self::GOAL_COLUMNS_PROCESS_GOALS_KEY
            ) {
                throw new ToolCallException(
                    "Use top-level goal parameters instead of apiParameters key '{$key}'."
                );
            }

            if (!is_scalar($value) && !is_array($value) && $value !== null) {
                throw new ToolCallException("Invalid apiParameters value for key '{$key}'.");
            }
        }

        return $apiParameters;
    }

    /**
     * @param array<string, mixed> $genericSafeParameters
     * @param list<mixed>|null $goalMetricsProcessGoals
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function extractRequestScopeParameters(
        array $genericSafeParameters,
        ?string $goalMetricsMode,
        ?array $goalMetricsProcessGoals,
        int|string|null $idGoal,
        bool $reportUsesIdGoalSelector
    ): array {
        $requestScopeParameters = $genericSafeParameters;
        /** @var array<string, string> $goalRequestParameters */
        $goalRequestParameters = [];

        $isSpecificGoalMode = $goalMetricsMode === GoalMetricsMode::SPECIFIC_GOAL->value;
        if ($isSpecificGoalMode && $reportUsesIdGoalSelector) {
            return [$requestScopeParameters, $goalRequestParameters];
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
                $this->normalizeGoalColumnsProcessGoals($goalMetricsProcessGoals)
            );
        }

        return [$requestScopeParameters, $goalRequestParameters];
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
                throw new ToolCallException(
                    'Invalid goalMetricsProcessGoals value: expected int or int-like string.'
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
                throw new ToolCallException(
                    "Invalid goalMetricsProcessGoals value '{$stringValue}': expected positive goal ID or "
                    . 'core ecommerce goal ID (ecommerceOrder, ecommerceAbandonedCart).'
                );
            }

            $normalized[] = $stringValue;
        }

        if ($normalized === []) {
            throw new ToolCallException(
                'Invalid goalMetricsProcessGoals value: at least one goal ID is required.'
            );
        }

        return array_values(array_unique($normalized));
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
        } catch (\Throwable $e) {
            throw new ToolCallException('Invalid period/date parameters.');
        }
    }

    /**
     * @param array<string, mixed> $apiParameters
     * @param array<string, mixed> $requestScopeParameters
     * @return array<string, mixed>
     */
    private function callProcessedReport(
        int $idSite,
        string $period,
        string $date,
        ReportMetadataRecord $reportMetadata,
        ?string $segment,
        array $apiParameters,
        array $requestScopeParameters,
        int|string|null $idGoal,
        ?int $idDimension,
        ?int $idSubtable,
        int $filterLimit,
        int $filterOffset
    ): array {
        if ($this->processedReportCaller === null) {
            try {
                Access::getInstance()->checkUserHasViewAccess($idSite);
            } catch (NoAccessException $e) {
                throw new ToolCallException('Report not found.');
            }
        }

        // Keep global request context aligned with tool-level inputs for Matomo hooks that read $_GET/$_REQUEST.
        // Keep pagination deterministic by forcing filter_limit/filter_offset in request scope.
        // TODO: remove this workaround once core exposes stable total-rows metadata for processed reports.
        $scopedRequestParameters = [
            'idSite' => (string) $idSite,
            'period' => $period,
            'date' => $date,
            'filter_limit' => (string) $filterLimit,
            'filter_offset' => (string) $filterOffset,
        ];
        $scopedRequestParameters = array_merge($scopedRequestParameters, $requestScopeParameters);
        $scopedRequestParameters['idSite'] = (string) $idSite;
        $scopedRequestParameters['period'] = $period;
        $scopedRequestParameters['date'] = $date;
        $scopedRequestParameters['filter_limit'] = (string) $filterLimit;
        $scopedRequestParameters['filter_offset'] = (string) $filterOffset;

        try {
            $processed = $this->getRequestScopeMutator->runWithParameters(
                $scopedRequestParameters,
                function () use (
                    $idSite,
                    $period,
                    $date,
                    $reportMetadata,
                    $segment,
                    $apiParameters,
                    $idGoal,
                    $idDimension,
                    $idSubtable
                ) {
                    return $this->translatorContextRunner->runInEnglish(function () use (
                        $idSite,
                        $period,
                        $date,
                        $reportMetadata,
                        $segment,
                        $apiParameters,
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
                            $idGoal,
                            $idDimension,
                            $idSubtable
                        );
                    });
                }
            );
        } catch (NoAccessException $e) {
            throw new ToolCallException('Report not found.');
        } catch (InfrastructureDataException $e) {
            throw new ToolCallException('Report data is invalid.');
        } catch (CoreApiRequestException $e) {
            $rootCause = $e->getPrevious() ?? $e;
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
                        $segment
                    );
                } catch (\Throwable $policyError) {
                    $shouldMapToStrictSegmentGuidance = false;
                }
            }

            if ($shouldMapToStrictSegmentGuidance) {
                throw new ToolCallException(self::STRICT_SEGMENT_ERROR_MESSAGE);
            }

            if (
                $this->isNoAccessLikeFailure($rootCause)
                && ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()
            ) {
                throw new ToolCallException('Report not found.');
            }

            throw new ToolCallException('Report retrieval failed.');
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable $e) {
            if (
                $this->isNoAccessLikeFailure($e)
                && ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()
            ) {
                throw new ToolCallException('Report not found.');
            }

            throw new ToolCallException('Report retrieval failed.');
        }

        return ToolDataNormalizer::requireStringKeyedArray($processed, 'Report data is invalid.');
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
     * @return array{0: array<string, mixed>, 1: int, 2: bool}
     */
    private function trimToRequestedLimit(array $report, int $requestedFilterLimit): array
    {
        $hasMore = false;
        $returnedRows = 0;
        $capturedReturnedRows = false;

        $reportData = $report['reportData'] ?? null;
        $reportMetadata = $report['reportMetadata'] ?? null;

        $this->trimParallelRows(
            $reportData,
            $reportMetadata,
            $requestedFilterLimit,
            $hasMore,
            $returnedRows,
            $capturedReturnedRows
        );

        if (array_key_exists('reportData', $report)) {
            $report['reportData'] = $reportData;
        }

        if (array_key_exists('reportMetadata', $report)) {
            $report['reportMetadata'] = $reportMetadata;
        }

        return [$report, $returnedRows, $hasMore];
    }

    private function trimParallelRows(
        mixed &$reportData,
        mixed &$reportMetadata,
        int $requestedFilterLimit,
        bool &$hasMore,
        int &$returnedRows,
        bool &$capturedReturnedRows
    ): void {
        if (is_array($reportData) && array_is_list($reportData)) {
            $rowCountBeforeTrim = count($reportData);
            if ($rowCountBeforeTrim > $requestedFilterLimit) {
                $hasMore = true;
            }

            $reportData = array_slice($reportData, 0, $requestedFilterLimit);
            if (is_array($reportMetadata) && array_is_list($reportMetadata)) {
                $reportMetadata = array_slice($reportMetadata, 0, $requestedFilterLimit);
            }

            if (!$capturedReturnedRows) {
                $returnedRows = count($reportData);
                $capturedReturnedRows = true;
            }

            return;
        }

        if (!is_array($reportData)) {
            return;
        }

        foreach ($reportData as $key => &$childData) {
            $childMetadata = null;
            $hasMetadataChild = false;
            if (is_array($reportMetadata) && array_key_exists($key, $reportMetadata)) {
                $childMetadata = $reportMetadata[$key];
                $hasMetadataChild = true;
            }

            $this->trimParallelRows(
                $childData,
                $childMetadata,
                $requestedFilterLimit,
                $hasMore,
                $returnedRows,
                $capturedReturnedRows
            );

            if ($hasMetadataChild) {
                $reportMetadata[$key] = $childMetadata;
            }
        }
        unset($childData);
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
        ?string $segment
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
                $segment
            );
        } catch (\Throwable $policyError) {
            $shouldMapToStrictSegmentGuidance = false;
        }

        if ($shouldMapToStrictSegmentGuidance) {
            throw new ToolCallException(self::STRICT_SEGMENT_ERROR_MESSAGE);
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
        int|string|null $idGoal,
        ?int $idDimension,
        ?int $idSubtable
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
                $idGoal,
                $idDimension,
                $idSubtable
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
            $idGoal,
            $idDimension,
            $idSubtable
        );
    }

    private function isNoAccessLikeFailure(\Throwable $e): bool
    {
        if ($e instanceof NoAccessException) {
            return true;
        }

        $message = strtolower(trim((string) $e->getMessage()));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'no access')
            || str_contains($message, 'checkuserhasviewaccess')
            || str_contains($message, 'view access');
    }

    private function isStrictSegmentRestrictionLikeFailure(\Throwable $e): bool
    {
        $current = $e;

        do {
            if ($current instanceof UnprocessedSegmentException) {
                return true;
            }

            $message = strtolower(trim((string) $current->getMessage()));
            if ($message !== '') {
                foreach (self::STRICT_SEGMENT_RESTRICTION_MESSAGE_FRAGMENTS as $fragment) {
                    if (str_contains($message, $fragment)) {
                        return true;
                    }
                }
            }

            $current = $current->getPrevious();
        } while ($current !== null);

        return false;
    }
}
