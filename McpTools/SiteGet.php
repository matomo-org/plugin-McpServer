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
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\GetApiWrapper;
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\GetApiWrapperInterface;
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\SiteRecord;

/**
 * @phpstan-import-type SiteArray from SiteRecord
 */
class SiteGet
{
    public const TOOL_NAME = 'matomo_site_get';

    public function __construct(private ?GetApiWrapperInterface $apiWrapper = null)
    {
    }

    /**
     * @return SiteArray
     */
    #[McpTool(
        name: self::TOOL_NAME,
        description: "Use when: idSite is known (from the user or " . SiteList::TOOL_NAME . ").\n"
            . "Purpose: fetch authoritative details for exactly one Matomo site.",
        outputSchema: [
            'type' => 'object',
            'properties' => [
                'idsite' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'main_url' => ['type' => 'string'],
                'timezone' => ['type' => 'string'],
                'timezone_name' => ['type' => 'string'],
                'currency' => ['type' => 'string'],
                'currency_name' => ['type' => 'string'],
                'ecommerce' => ['type' => 'boolean'],
                'sitesearch' => ['type' => 'boolean'],
                'type' => ['type' => 'string'],
            ],
            'required' => [
                'idsite',
                'name',
                'main_url',
                'timezone',
                'timezone_name',
                'currency',
                'currency_name',
                'ecommerce',
                'sitesearch',
                'type',
            ],
            'additionalProperties' => false,
        ]
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
        // See SitesManager\GetApiWrapper for normalization contract and intentional
        // not-found/access-denied message collapsing behavior.
        $site = $this->getApiWrapper()->getSiteRecordFromId($idSite);
        return $site->toArray();
    }

    private function getApiWrapper(): GetApiWrapperInterface
    {
        return $this->apiWrapper ??= new GetApiWrapper();
    }
}
