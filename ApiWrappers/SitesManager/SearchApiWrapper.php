<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\SitesManager;

final class SearchApiWrapper implements SearchApiWrapperInterface
{
    public function __construct(private ?SiteSummaryQueryServiceInterface $queryService = null)
    {
    }

    /**
     * @return array<int, SiteSummaryRecord>
     */
    public function searchSitesWithViewAccess(string $search): array
    {
        return $this->getQueryService()->getSiteSummariesForSearch($search);
    }

    private function getQueryService(): SiteSummaryQueryServiceInterface
    {
        return $this->queryService ??= new SiteSummaryQueryService();
    }
}
