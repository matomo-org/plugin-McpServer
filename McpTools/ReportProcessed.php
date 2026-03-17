<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Capability\Attribute\McpTool;
use Matomo\Dependencies\McpServer\Mcp\Capability\Attribute\Schema;
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportProcessedRecord;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportProcessedToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\McpServer\Support\Reports\GoalMetricsMode;

/**
 * @phpstan-import-type ReportProcessedArray from ReportProcessedRecord
 */
class ReportProcessed
{
    public const TOOL_NAME = 'matomo_report_processed';
    public const FILTER_LIMIT_DEFAULT = 50;
    public const FILTER_LIMIT_MAX = 250;
    public const FILTER_OFFSET_DEFAULT = 0;

    public function __construct(private ReportProcessedQueryServiceInterface $queryService)
    {
    }

    /**
     * @param array<string, mixed>|null $apiParameters
     * @param list<int|string>|null $goalMetricsProcessGoals
     * @return ReportProcessedArray
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: you need tabular processed report data for a known report and date range.\n"
            . "Purpose: resolve report selector, fetch processed report payload, "
            . "and return stable pagination metadata.\n"
            . "Next: inspect reportData/columns/reportMetadata, then refine filters or query another report.",
        // Keep readOnlyHint=false as the safe default:
        // depending on dynamic Matomo archiving configuration, fetching processed reports
        // can trigger report generation/archiving side effects. The true read-only value
        // must be derived at runtime from current configuration.
        annotations: new ToolAnnotations(readOnlyHint: false, openWorldHint: false),
        outputSchema: ReportProcessedToolOutputSchema::ITEM
    )]
    #[Schema(definition: [
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
                'description' => 'Matomo period (day, week, month, year, range).',
            ],
            'date' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Matomo date expression.',
            ],
            'reportUniqueId' => [
                'type' => 'string',
                'minLength' => 1,
                'description' => 'Preferred selector from matomo_report_list.',
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
                'oneOf' => [
                    [
                        'type' => 'object',
                        'additionalProperties' => true,
                        'description' => 'Optional report parameters '
                            . '(safe generic params and report-specific selector params).',
                    ],
                    [
                        'type' => 'array',
                        'maxItems' => 0,
                        'description' => 'Empty array is accepted and normalized as no apiParameters.',
                    ],
                ],
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
        // Selector truth table:
        // valid:   reportUniqueId
        // valid:   apiModule + apiAction
        // invalid: no selector, partial module/action selector, or reportUniqueId combined
        //          with apiModule/apiAction
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
    ])]
    public function get(
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
        ?int $filter_offset = null
    ): array {
        $apiParameters = $apiParameters === null
            ? null
            : ToolDataNormalizer::requireStringKeyedArrayOrEmptyList($apiParameters, 'apiParameters');

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
            filterOffset: $filter_offset ?? self::FILTER_OFFSET_DEFAULT
        )->toArray();
    }
}
