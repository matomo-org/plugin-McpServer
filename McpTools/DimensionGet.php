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
use Piwik\Plugins\McpServer\ApiWrappers\CustomDimensions\GetApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionDetailRecord;
use Piwik\Plugins\McpServer\Contracts\Dimensions\GetApiWrapperInterface;
use Piwik\Plugins\McpServer\Schemas\Dimensions\DimensionDetailToolOutputSchema;

/**
 * @phpstan-import-type DimensionDetailArray from DimensionDetailRecord
 */
class DimensionGet
{
    public const TOOL_NAME = 'matomo_dimension_get';

    public function __construct(private ?GetApiWrapperInterface $apiWrapper = null)
    {
    }

    /**
     * @return DimensionDetailArray
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: idSite and idDimension are known.\n"
            . "Purpose: fetch authoritative details for exactly one configured custom dimension.\n"
            . "Do not use: if dimension id is unknown—discover candidates first.",
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
        outputSchema: DimensionDetailToolOutputSchema::ITEM
    )]
    #[Schema(
        type: 'object',
        properties: [
            'idSite' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Matomo site ID used to scope custom dimension lookup.',
            ],
            'idDimension' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Custom dimension ID (idcustomdimension) configured for the site.',
            ],
        ],
        required: ['idSite', 'idDimension'],
        additionalProperties: false
    )]
    public function get(int $idSite, int $idDimension): array
    {
        return $this->getApiWrapper()->getDimensionById($idSite, $idDimension)->toArray();
    }

    private function getApiWrapper(): GetApiWrapperInterface
    {
        return $this->apiWrapper ??= new GetApiWrapper();
    }
}
