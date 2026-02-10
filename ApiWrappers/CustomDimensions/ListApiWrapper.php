<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\CustomDimensions;

use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionSummaryRecord;
use Piwik\Plugins\McpServer\Contracts\Dimensions\ListApiWrapperInterface;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionSummaryQueryService;

final class ListApiWrapper implements ListApiWrapperInterface
{
    public function __construct(private ?DimensionSummaryQueryServiceInterface $queryService = null)
    {
    }

    /**
     * @return array<int, DimensionSummaryRecord>
     */
    public function getDimensionsForSite(int $idSite): array
    {
        return $this->getQueryService()->getDimensionSummariesForSite($idSite);
    }

    private function getQueryService(): DimensionSummaryQueryServiceInterface
    {
        return $this->queryService ??= new DimensionSummaryQueryService();
    }
}
