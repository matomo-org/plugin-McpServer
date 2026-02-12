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
use Piwik\Plugins\McpServer\ApiWrappers\SegmentEditor\GetApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Segments\GetApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentDetailRecord;
use Piwik\Plugins\McpServer\Schemas\Segments\SegmentDetailToolOutputSchema;

/**
 * @phpstan-import-type SegmentDetailArray from SegmentDetailRecord
 */
class SegmentGet
{
    public const TOOL_NAME = 'matomo_segment_get';

    public function __construct(private ?GetApiWrapperInterface $apiWrapper = null)
    {
    }

    /**
     * @return SegmentDetailArray
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: you need details for one saved segment.\n"
            . "Purpose: resolve a segment by idSegment, exact name, or exact definition within idSite scope.\n"
            . "Next: use the returned definition in analytics/report API calls.",
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
        outputSchema: SegmentDetailToolOutputSchema::ITEM
    )]
    #[Schema(definition: [
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
        'oneOf' => [
            ['required' => ['idSegment']],
            ['required' => ['name']],
            ['required' => ['definition']],
        ],
        'additionalProperties' => false,
    ])]
    public function get(
        int $idSite,
        ?int $idSegment = null,
        ?string $name = null,
        ?string $definition = null
    ): array {
        return $this->getApiWrapper()->getSegmentBySelector(
            $idSite,
            $idSegment,
            $name,
            $definition
        )->toArray();
    }

    private function getApiWrapper(): GetApiWrapperInterface
    {
        return $this->apiWrapper ??= new GetApiWrapper();
    }
}
