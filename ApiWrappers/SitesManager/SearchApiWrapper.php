<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\SitesManager;

use Piwik\Plugins\McpServer\Contracts\Sites\SearchApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryRecord;
use Piwik\Plugins\McpServer\Services\Sites\SiteSummaryQueryService;

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
