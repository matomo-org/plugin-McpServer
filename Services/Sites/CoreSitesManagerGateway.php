<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Sites;

use Piwik\Plugins\McpServer\Contracts\Ports\Sites\CoreSitesManagerGatewayInterface;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;

final class CoreSitesManagerGateway implements CoreSitesManagerGatewayInterface
{
    public function getSitesWithMinimumAccess(string $minimumAccess, string $search, ?int $limit)
    {
        return SitesManagerApi::getInstance()->getSitesWithMinimumAccess($minimumAccess, $search, $limit);
    }

    public function getSiteFromId(int $idSite)
    {
        return SitesManagerApi::getInstance()->getSiteFromId($idSite);
    }
}
