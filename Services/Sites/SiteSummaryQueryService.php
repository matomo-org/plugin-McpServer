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
use Piwik\Access\Role\View;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;

final class SiteSummaryQueryService implements SiteSummaryQueryServiceInterface
{
    /**
     * @return array<int, SiteSummaryRecord>
     */
    public function getSiteSummariesForList(): array
    {
        return $this->fetchSiteSummaries(
            search: '',
            invalidDataMessage: 'Site list data is invalid.',
            context: 'Site list item'
        );
    }

    /**
     * @return array<int, SiteSummaryRecord>
     */
    public function getSiteSummariesForSearch(string $search): array
    {
        return $this->fetchSiteSummaries(
            search: $search,
            invalidDataMessage: 'Site search data is invalid.',
            context: 'Site search item'
        );
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $site
     */
    public function normalizeSiteSummaryData(array $site, string $context): SiteSummaryRecord
    {
        return new SiteSummaryRecord(
            idSite: ToolDataNormalizer::requireIntLikeField($site, 'idsite', $context),
            name: ToolDataNormalizer::requireStringField($site, 'name', $context),
            mainUrl: ToolDataNormalizer::requireStringField($site, 'main_url', $context),
            type: ToolDataNormalizer::requireStringField($site, 'type', $context),
        );
    }

    /**
     * Public for testability and to keep top-level payload-shape validation centralized.
     *
     * @param mixed $sites
     * @return array<int, SiteSummaryRecord>
     */
    public function normalizeSiteSummaryRows(
        mixed $sites,
        string $invalidDataMessage,
        string $context
    ): array {
        if (!is_array($sites)) {
            throw new ToolCallException($invalidDataMessage);
        }

        $result = [];
        foreach ($sites as $site) {
            if (!is_array($site)) {
                throw new ToolCallException($invalidDataMessage);
            }
            $result[] = $this->normalizeSiteSummaryData($site, $context);
        }

        return $result;
    }

    /**
     * @return array<int, SiteSummaryRecord>
     */
    private function fetchSiteSummaries(string $search, string $invalidDataMessage, string $context): array
    {
        try {
            $sites = SitesManagerApi::getInstance()->getSitesWithMinimumAccess(View::ID, $search, null);
        } catch (NoAccessException $e) {
            // Keep list/search behavior aligned: no view access yields no rows.
            return [];
        } catch (\Throwable $e) {
            if (ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
                // Compatibility fallback for no-access backends that do not throw NoAccessException.
                return [];
            }

            throw new ToolCallException('Site retrieval failed.');
        }

        return $this->normalizeSiteSummaryRows($sites, $invalidDataMessage, $context);
    }
}
