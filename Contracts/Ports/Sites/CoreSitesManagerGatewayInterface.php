<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Sites;

interface CoreSitesManagerGatewayInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function getSitesWithMinimumAccess(string $minimumAccess, string $search, ?int $limit): array;

    /**
     * @return array<string, mixed>
     */
    public function getSiteFromId(int $idSite): array;
}
