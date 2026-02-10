<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\CustomDimensions;

use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Dimensions\DimensionDetailRecord;
use Piwik\Plugins\McpServer\Contracts\Dimensions\GetApiWrapperInterface;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionDetailQueryService;

final class GetApiWrapper implements GetApiWrapperInterface
{
    public function __construct(private ?DimensionDetailQueryServiceInterface $queryService = null)
    {
    }

    public function getDimensionById(int $idSite, int $idDimension): DimensionDetailRecord
    {
        return $this->getQueryService()->getDimensionDetailForSite($idSite, $idDimension);
    }

    private function getQueryService(): DimensionDetailQueryServiceInterface
    {
        return $this->queryService ??= new DimensionDetailQueryService();
    }
}
