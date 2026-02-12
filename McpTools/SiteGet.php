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
use Piwik\Plugins\McpServer\Contracts\Records\Sites\SiteDetailRecord;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Schemas\Sites\SiteDetailToolOutputSchema;
use Piwik\Plugins\McpServer\Services\Sites\SiteDetailQueryService;

/**
 * @phpstan-import-type SiteDetailArray from SiteDetailRecord
 */
class SiteGet
{
    public const TOOL_NAME = 'matomo_site_get';

    public function __construct(private ?SiteDetailQueryServiceInterface $queryService = null)
    {
    }

    /**
     * @return SiteDetailArray
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: idSite is known"
            . " (from the user, " . SiteList::TOOL_NAME . ", or " . SiteSearch::TOOL_NAME . ").\n"
            . "Purpose: fetch authoritative details for exactly one Matomo site.\n"
            . "Do not use: if you only have URL/domain/name—use"
            . " " . SiteList::TOOL_NAME . " or " . SiteSearch::TOOL_NAME . " first.",
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
        outputSchema: SiteDetailToolOutputSchema::ITEM
    )]
    #[Schema(
        type: 'object',
        properties: [
            'idSite' => [
                'type' => 'integer',
                'minimum' => 1,
                'description' => 'Matomo site ID',
            ],
        ],
        required: ['idSite'],
        additionalProperties: false
    )]
    public function get(int $idSite): array
    {
        // See SiteDetailQueryService for normalization contract and intentional
        // not-found/access-denied message collapsing behavior.
        $site = $this->getQueryService()->getSiteDetailFromId($idSite);
        return $site->toArray();
    }

    private function getQueryService(): SiteDetailQueryServiceInterface
    {
        return $this->queryService ??= new SiteDetailQueryService();
    }
}
