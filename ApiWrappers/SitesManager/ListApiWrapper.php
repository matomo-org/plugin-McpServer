<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\SitesManager;

use Piwik\Plugins\McpServer\Contracts\Sites\ListApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteSummaryRecord;
use Piwik\Plugins\McpServer\Services\Sites\SiteSummaryQueryService;

final class ListApiWrapper implements ListApiWrapperInterface
{
    public function __construct(private ?SiteSummaryQueryServiceInterface $queryService = null)
    {
    }

    /**
     * @return array<int, SiteSummaryRecord>
     */
    public function getSitesWithViewAccess(): array
    {
        return $this->getQueryService()->getSiteSummariesForList();
    }

    private function getQueryService(): SiteSummaryQueryServiceInterface
    {
        return $this->queryService ??= new SiteSummaryQueryService();
    }
}
