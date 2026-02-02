<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\SitesManager;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\NoAccessException;
use Piwik\Access;
use Piwik\Access\Role\View;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;

final class SearchApiWrapper implements SearchApiWrapperInterface
{
    /**
     * @return array<int, SiteSummaryRecord>
     */
    public function searchSitesWithViewAccess(string $search): array
    {
        $siteIds = Access::getInstance()->getSitesIdWithAtLeastViewAccess();
        if (!is_array($siteIds) || $siteIds === []) {
            return [];
        }

        try {
            $sites = SitesManagerApi::getInstance()->getSitesWithMinimumAccess(View::ID, $search, null);
        } catch (NoAccessException $e) {
            // Keep list/search behavior aligned: no view access yields no rows.
            return [];
        }

        $result = [];

        foreach ($sites as $site) {
            if (!is_array($site)) {
                throw new ToolCallException('Site search data is invalid.');
            }

            $result[] = $this->normalizeSiteSummaryData($site);
        }

        return $result;
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $site
     */
    public function normalizeSiteSummaryData(array $site): SiteSummaryRecord
    {
        $context = 'Site search item';

        return new SiteSummaryRecord(
            idSite: ToolDataNormalizer::requireIntLikeField($site, 'idsite', $context),
            name: ToolDataNormalizer::requireStringField($site, 'name', $context),
            mainUrl: ToolDataNormalizer::requireStringField($site, 'main_url', $context),
            type: ToolDataNormalizer::requireStringField($site, 'type', $context),
        );
    }
}
