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
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Dimensions\DimensionDetailRecord;
use Piwik\Plugins\McpServer\Schemas\Dimensions\DimensionToolSchemas;

/**
 * @phpstan-import-type DimensionDetailArray from DimensionDetailRecord
 */
class DimensionGet extends McpTool
{
    public const TOOL_NAME = 'matomo_dimension_get';

    public function __construct(private DimensionDetailQueryServiceInterface $queryService)
    {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: idSite and idDimension are known.\n"
            . "Purpose: fetch authoritative details for exactly one configured custom dimension.\n"
            . "Do not use: if dimension id is unknown—discover candidates first.";
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
                    'description' => 'Matomo site ID used to scope custom dimension lookup.',
                ],
                'idDimension' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Custom dimension ID (idcustomdimension) configured for the site.',
                ],
            ],
            'required' => ['idSite', 'idDimension'],
            'additionalProperties' => false,
        ];
        $this->outputSchema = DimensionToolSchemas::DETAIL;
    }

    /**
     * @return DimensionDetailArray
     */
    public function execute(int $idSite, int $idDimension): array
    {
        return $this->queryService->getDimensionDetailForSite($idSite, $idDimension)->toArray();
    }
}
