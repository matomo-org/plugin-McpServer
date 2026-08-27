<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\McpToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportProcessedRecord;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportProcessedToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\McpServer\Support\Reports\GoalMetricsMode;
use Piwik\Plugins\McpServer\Support\Reports\PeriodDateNormalizer;

/**
 * @phpstan-import-type ReportProcessedArray from ReportProcessedRecord
 */
class ReportProcessed extends McpTool
{
    public const TOOL_NAME = 'matomo_report_processed';
    public const FILTER_LIMIT_DEFAULT = 50;
    public const FILTER_LIMIT_MAX = 250;
    public const FILTER_OFFSET_DEFAULT = 0;

    public function __construct(private ReportProcessedQueryServiceInterface $queryService)
    {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need tabular processed report data "
            . "for a known report and date range.\n"
            . "Purpose: resolve report selector, fetch processed report payload, "
            . "and return stable pagination metadata.\n"
            . "Next: inspect reportData/columns/reportMetadata, then refine filters or query another report.";
        // Classify archive materialization triggered while serving a report as
        // non-mutational for MCP, independent of the archiving configuration.
        $this->annotations = new McpToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
        $this->inputSchema = [
            'type' => 'object',
            'properties' => [
                'idSite' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Matomo site ID.',
                ],
                'period' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Matomo period granularity: day, week, month, year, or range. Use range for one '
                        . 'aggregate over a custom span - either an explicit "start,end" date or a rolling '
                        . 'lastN/previousN window (e.g. period=range + date=last7 = one total over the last 7 days). '
                        . 'For a single bucket use day/week/month/year with one date inside it.',
                ],
                'date' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Matomo date, paired with period. A single date (YYYY-MM-DD) or keyword '
                        . '(today, yesterday, lastWeek, lastMonth, lastYear) returns the one containing period as a '
                        . 'flat result - prefer this for a whole day/week/month/year. With period=range, either '
                        . '"YYYY-MM-DD,YYYY-MM-DD" or a rolling lastN/previousN window (measured in days, e.g. '
                        . 'last7 = the last 7 days) returns ONE aggregate over the whole span. With '
                        . 'period=day/week/month/year, lastN/previousN (N periods, not days: period=week + last7 = '
                        . '7 weeks) or a comma date returns one entry PER sub-period - use only for an intended '
                        . 'per-period breakdown.',
                ],
                'reportUniqueId' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Preferred selector from matomo_report_list. Use the '
                        . 'underscore form (e.g. VisitsSummary_get), not the API-method '
                        . 'form VisitsSummary.get.',
                ],
                'apiModule' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Report API module when reportUniqueId is not used.',
                ],
                'apiAction' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Report API action when reportUniqueId is not used.',
                ],
                'apiParameters' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                    'description' => 'Optional report parameters '
                        . '(safe generic params and report-specific selector params). '
                        . 'An empty array is treated equivalently to an empty object.',
                ],
                'goalMetricsMode' => [
                    'type' => 'string',
                    'enum' => GoalMetricsMode::SCHEMA_VALUES,
                    'description' => 'Optional goal metrics mode. '
                        . 'Controls how goal columns are added to report output. '
                        . 'Allowed values are enforced by the schema enum. '
                        . 'Use specific_goal together with idGoal.',
                ],
                'goalMetricsProcessGoals' => [
                    'type' => 'array',
                    'items' => [
                        'oneOf' => [
                            ['type' => 'integer', 'minimum' => 1],
                            [
                                'type' => 'string',
                                'pattern' => '^(?:[1-9][0-9]*|ecommerceOrder|ecommerceAbandonedCart)$',
                            ],
                        ],
                    ],
                    'description' => 'Optional list of goal IDs (or core ecommerce IDs ecommerceOrder, '
                        . 'ecommerceAbandonedCart) to force/limit goal metrics processing for the report.',
                ],
                'segment' => [
                    'type' => 'string',
                    'description' => 'Optional segment expression.',
                ],
                'idGoal' => [
                    'oneOf' => [
                        ['type' => 'integer'],
                        ['type' => 'string'],
                    ],
                    'description' => 'Optional goal id, passed to API.getProcessedReport. When used with '
                        . 'specific_goal, pass a positive integer or core ecommerce ID '
                        . '(ecommerceOrder/ecommerceAbandonedCart).',
                ],
                'idDimension' => [
                    'type' => 'integer',
                    'description' => 'Optional custom dimension id, passed to API.getProcessedReport.',
                ],
                'idSubtable' => [
                    'type' => 'integer',
                    'description' => 'Optional subtable id, passed to API.getProcessedReport.',
                ],
                'filter_limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::FILTER_LIMIT_MAX,
                    'default' => self::FILTER_LIMIT_DEFAULT,
                    'description' => 'Rows per page. Uses schema default and maximum constraints.',
                ],
                'filter_offset' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => self::FILTER_OFFSET_DEFAULT,
                    'description' => 'Pagination offset (default 0).',
                ],
            ],
            'required' => ['idSite', 'period', 'date'],
            'not' => [
                'anyOf' => [
                    [
                        'not' => [
                            'anyOf' => [
                                ['required' => ['reportUniqueId']],
                                ['required' => ['apiModule']],
                                ['required' => ['apiAction']],
                            ],
                        ],
                    ],
                    ['required' => ['reportUniqueId', 'apiModule']],
                    ['required' => ['reportUniqueId', 'apiAction']],
                    [
                        'required' => ['apiModule'],
                        'not' => ['required' => ['apiAction']],
                    ],
                    [
                        'required' => ['apiAction'],
                        'not' => ['required' => ['apiModule']],
                    ],
                ],
            ],
            'additionalProperties' => false,
        ];
        $this->outputSchema = ReportProcessedToolOutputSchema::ITEM;
    }

    /**
     * @param array<string, mixed>|null $apiParameters
     * @param list<int|string>|null $goalMetricsProcessGoals
     * @return ReportProcessedArray
     */
    public function execute(
        int $idSite,
        string $period,
        string $date,
        ?string $reportUniqueId = null,
        ?string $apiModule = null,
        ?string $apiAction = null,
        ?array $apiParameters = null,
        ?string $goalMetricsMode = null,
        ?array $goalMetricsProcessGoals = null,
        ?string $segment = null,
        int|string|null $idGoal = null,
        ?int $idDimension = null,
        ?int $idSubtable = null,
        ?int $filter_limit = null,
        ?int $filter_offset = null,
    ): array {
        $apiParameters = $apiParameters === null
            ? null
            : ToolDataNormalizer::requireStringKeyedArrayOrEmptyList($apiParameters, 'apiParameters');

        // Expand whole-bucket shorthand dates (year "2026", month "2026-01") into
        // the full YYYY-MM-DD form Matomo requires; see PeriodDateNormalizer.
        $date = PeriodDateNormalizer::normalize($period, $date);

        return $this->queryService->getProcessedReport(
            idSite: $idSite,
            period: $period,
            date: $date,
            reportUniqueId: $reportUniqueId,
            apiModule: $apiModule,
            apiAction: $apiAction,
            apiParameters: $apiParameters,
            goalMetricsMode: $goalMetricsMode,
            goalMetricsProcessGoals: $goalMetricsProcessGoals,
            segment: $segment,
            idGoal: $idGoal,
            idDimension: $idDimension,
            idSubtable: $idSubtable,
            filterLimit: $filter_limit ?? self::FILTER_LIMIT_DEFAULT,
            filterOffset: $filter_offset ?? self::FILTER_OFFSET_DEFAULT,
        )->toArray();
    }
}
