<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Sites;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\CoreSitesManagerGatewayInterface;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;

final class CoreSitesManagerGateway implements CoreSitesManagerGatewayInterface
{
    public function getSitesWithMinimumAccess(string $minimumAccess, string $search, ?int $limit): array
    {
        $sites = SitesManagerApi::getInstance()->getSitesWithMinimumAccess($minimumAccess, $search, $limit);

        return $this->normalizeRows($sites, 'Site list data is invalid.');
    }

    public function getSiteFromId(int $idSite): array
    {
        $site = SitesManagerApi::getInstance()->getSiteFromId($idSite);

        return ToolDataNormalizer::requireStringKeyedArray($site, 'Site data is invalid.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(mixed $rows, string $invalidDataMessage): array
    {
        if (!is_array($rows)) {
            throw new ToolCallException($invalidDataMessage);
        }

        if (!array_is_list($rows)) {
            $rows = array_values($rows);
        }

        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = ToolDataNormalizer::requireStringKeyedArray($row, $invalidDataMessage);
        }

        return $normalized;
    }
}
