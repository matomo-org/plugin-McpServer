<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Sites;

interface SiteSummaryQueryServiceInterface
{
    /**
     * @return array<int, SiteSummaryRecord>
     */
    public function getSiteSummariesForList(): array;

    /**
     * @return array<int, SiteSummaryRecord>
     */
    public function getSiteSummariesForSearch(string $search): array;
}
