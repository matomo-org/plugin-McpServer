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
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Sites\SiteDetailRecord;
use Piwik\Plugins\McpServer\Schemas\Sites\SiteDetailToolOutputSchema;

/**
 * @phpstan-import-type SiteDetailArray from SiteDetailRecord
 */
class SiteGet extends McpTool
{
    public const TOOL_NAME = 'matomo_site_get';

    public function __construct(private SiteDetailQueryServiceInterface $queryService)
    {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: idSite is known"
            . " (from the user, " . SiteList::TOOL_NAME . ", or " . SiteSearch::TOOL_NAME . ").\n"
            . "Purpose: fetch authoritative details for exactly one Matomo site.\n"
            . "Do not use: if you only have URL/domain/name—use"
            . " " . SiteList::TOOL_NAME . " or " . SiteSearch::TOOL_NAME . " first.";
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
                    'description' => 'Matomo site ID',
                ],
            ],
            'required' => ['idSite'],
            'additionalProperties' => false,
        ];
        $this->outputSchema = SiteDetailToolOutputSchema::ITEM;
    }

    /**
     * @return SiteDetailArray
     */
    public function execute(int $idSite): array
    {
        // See SiteDetailQueryService for normalization contract and intentional
        // not-found/access-denied message collapsing behavior.
        $site = $this->queryService->getSiteDetailFromId($idSite);
        return $site->toArray();
    }
}
