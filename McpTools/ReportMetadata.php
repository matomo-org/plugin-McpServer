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
use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\Records\Reports\ReportMetadataRecord;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Schemas\Reports\ReportMetadataToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;

/**
 * @phpstan-import-type ReportMetadataArray from ReportMetadataRecord
 */
class ReportMetadata
{
    public const TOOL_NAME = 'matomo_report_metadata';

    public function __construct(private ReportMetadataQueryServiceInterface $queryService)
    {
    }

    /**
     * @param array<string, mixed>|null $apiParameters
     * @return ReportMetadataArray
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: you need full metadata for one report in a site scope.\n"
            . "Purpose: resolve one report by reportUniqueId (preferred) or module/action selector.\n"
            . "Next: use the returned metadata and parameters for reporting API calls.\n"
            . ToolOutputSecurity::SAFETY_WARNING_TEXT,
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
        outputSchema: ReportMetadataToolOutputSchema::ITEM
    )]
    #[Schema(definition: [
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
                'description' => 'Preferred selector from matomo_report_list (uniqueId).',
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
                'description' => 'Optional report parameter object used with module/action lookup.',
                'additionalProperties' => true,
            ],
        ],
        'required' => ['idSite'],
        'oneOf' => [
            [
                'required' => ['reportUniqueId'],
                'not' => [
                    'anyOf' => [
                        ['required' => ['apiModule']],
                        ['required' => ['apiAction']],
                        ['required' => ['apiParameters']],
                    ],
                ],
            ],
            [
                'required' => ['apiModule', 'apiAction'],
                'not' => ['required' => ['reportUniqueId']],
            ],
        ],
        'additionalProperties' => false,
    ])]
    public function get(
        int $idSite,
        ?string $reportUniqueId = null,
        ?string $apiModule = null,
        ?string $apiAction = null,
        ?string $period = null,
        ?string $date = null,
        ?array $apiParameters = null
    ): array {
        if ($reportUniqueId !== null) {
            if (
                $apiModule !== null
                || $apiAction !== null
                || $apiParameters !== null
            ) {
                throw new ToolCallException(
                    'Invalid parameter combination: reportUniqueId cannot be combined '
                    . 'with apiModule, apiAction, or apiParameters.'
                );
            }

            $report = $this->queryService->getReportMetadataByUniqueId($idSite, $reportUniqueId)->toArray();
            return ['security' => ToolOutputSecurity::buildForTool(self::TOOL_NAME)] + $report;
        }

        $report = $this->queryService->getReportMetadataByModuleAction(
            $idSite,
            (string) $apiModule,
            (string) $apiAction,
            $apiParameters ?? [],
            $period ?? 'day',
            $date ?? 'today'
        )->toArray();
        return ['security' => ToolOutputSecurity::buildForTool(self::TOOL_NAME)] + $report;
    }
}
