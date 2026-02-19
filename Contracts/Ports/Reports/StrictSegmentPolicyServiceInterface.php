<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Reports;

interface StrictSegmentPolicyServiceInterface
{
    public function shouldMapToStrictSegmentGuidance(
        int $idSite,
        string $period,
        string $date,
        ?string $segment
    ): bool;
}
