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
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportMetadataToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

/**
 * @phpstan-import-type ReportMetadataArray from ReportMetadataRecord
 */
class ReportMetadata extends McpTool
{
    public const TOOL_NAME = 'matomo_report_metadata';

    public function __construct(private ReportMetadataQueryServiceInterface $queryService)
    {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need full metadata for one report in a site scope,"
            . " including subtable reports.\n"
            . "Purpose: resolve one report by reportUniqueId (preferred) or module/action selector.\n"
            . "Next: use the returned metadata and parameters for reporting API calls.";
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
                    'description' => 'Matomo site ID used to scope report metadata lookup.',
                ],
                'reportUniqueId' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Preferred selector from matomo_report_list (uniqueId). Use the '
                        . 'underscore form (e.g. VisitsSummary_get), not the API-method '
                        . 'form VisitsSummary.get.',
                ],
                'apiModule' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'API module name, used with apiAction when reportUniqueId is not provided.',
                ],
                'apiAction' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'API action name, used with apiModule when reportUniqueId is not provided.',
                ],
                'period' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Period for module/action metadata lookup (default day).',
                ],
                'date' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Date for module/action metadata lookup (default today).',
                ],
                'apiParameters' => [
                    'type' => 'object',
                    'description' => 'Optional report parameter object used with module/action lookup. '
                        . 'An empty array is treated equivalently to an empty object.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['idSite'],
            // Selector truth table:
            // valid:   reportUniqueId
            // valid:   apiModule + apiAction
            // invalid: no selector, partial module/action selector, or reportUniqueId combined
            //          with apiModule/apiAction/non-empty apiParameters
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
                        'allOf' => [
                            ['required' => ['reportUniqueId', 'apiParameters']],
                            [
                                'anyOf' => [
                                    [
                                        'properties' => [
                                            'apiParameters' => [
                                                'type' => 'object',
                                                'minProperties' => 1,
                                            ],
                                        ],
                                    ],
                                    [
                                        'properties' => [
                                            'apiParameters' => [
                                                'type' => 'array',
                                                'minItems' => 1,
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
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
        $this->outputSchema = ReportMetadataToolOutputSchema::ITEM;
    }

    /**
     * @param array<string, mixed>|null $apiParameters
     * @return ReportMetadataArray
     */
    public function execute(
        int $idSite,
        ?string $reportUniqueId = null,
        ?string $apiModule = null,
        ?string $apiAction = null,
        ?string $period = null,
        ?string $date = null,
        ?array $apiParameters = null,
    ): array {
        $apiParameters = $apiParameters === null
            ? null
            : ToolDataNormalizer::requireStringKeyedArrayOrEmptyList($apiParameters, 'apiParameters');

        if ($reportUniqueId !== null) {
            if (
                $apiModule !== null
                || $apiAction !== null
                || ($apiParameters !== null && $apiParameters !== [])
            ) {
                $this->fail(
                    'Invalid parameter combination: reportUniqueId cannot be combined '
                    . 'with apiModule, apiAction, or non-empty apiParameters.',
                );
            }

            return $this->queryService->getReportMetadataByUniqueId($idSite, $reportUniqueId)->toArray();
        }

        return $this->queryService->getReportMetadataByModuleAction(
            $idSite,
            (string) $apiModule,
            (string) $apiAction,
            $apiParameters ?? [],
            $period ?? 'day',
            $date ?? 'today',
        )->toArray();
    }
}
