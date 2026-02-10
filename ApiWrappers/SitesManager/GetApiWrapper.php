<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\SitesManager;

use Piwik\Plugins\McpServer\Contracts\Sites\GetApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Sites\SiteDetailRecord;
use Piwik\Plugins\McpServer\Services\Sites\SiteDetailQueryService;

final class GetApiWrapper implements GetApiWrapperInterface
{
    public function __construct(private ?SiteDetailQueryServiceInterface $queryService = null)
    {
    }

    public function getSiteDetailFromId(int $idSite): SiteDetailRecord
    {
        return $this->getQueryService()->getSiteDetailFromId($idSite);
    }

    private function getQueryService(): SiteDetailQueryServiceInterface
    {
        return $this->queryService ??= new SiteDetailQueryService();
    }
}
