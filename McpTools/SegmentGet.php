<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Segments\SegmentDetailRecord;
use Piwik\Plugins\McpServer\Schemas\Segments\SegmentDetailToolOutputSchema;

/**
 * @phpstan-import-type SegmentDetailArray from SegmentDetailRecord
 */
class SegmentGet extends McpTool
{
    public const TOOL_NAME = 'matomo_segment_get';

    public function __construct(private SegmentDetailQueryServiceInterface $queryService)
    {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need details for one saved segment.\n"
            . "Purpose: resolve a segment by idSegment, exact name, or exact definition within idSite scope.\n"
            . "Next: use the returned definition in analytics/report API calls.";
        $this->annotations = new ToolAnnotations(
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
                    'description' => 'Matomo site ID used to scope segment lookup.',
                ],
                'idSegment' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Saved segment ID.',
                ],
                'name' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Exact segment name (case-sensitive).',
                ],
                'definition' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Exact segment definition (case-sensitive).',
                ],
            ],
            'required' => ['idSite'],
            // Enforce exactly one selector today (idSegment|name|definition).
            // If future optional top-level inputs are added, these bounds must be revisited.
            'minProperties' => 2,
            'maxProperties' => 2,
            'additionalProperties' => false,
        ];
        $this->outputSchema = SegmentDetailToolOutputSchema::ITEM;
    }

    /**
     * @return SegmentDetailArray
     */
    public function execute(
        int $idSite,
        ?int $idSegment = null,
        ?string $name = null,
        ?string $definition = null,
    ): array {
        return $this->queryService->getSegmentBySelector(
            $idSite,
            $idSegment,
            $name,
            $definition,
        )->toArray();
    }
}
