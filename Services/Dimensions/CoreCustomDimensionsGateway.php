<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Dimensions;

use Piwik\Plugin\Manager;
use Piwik\Plugins\CustomDimensions\API as CustomDimensionsApi;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\CoreCustomDimensionsGatewayInterface;

final class CoreCustomDimensionsGateway implements CoreCustomDimensionsGatewayInterface
{
    public function isCustomDimensionsPluginActivated(): bool
    {
        return Manager::getInstance()->isPluginActivated('CustomDimensions');
    }

    public function getConfiguredCustomDimensions(int $idSite)
    {
        return CustomDimensionsApi::getInstance()->getConfiguredCustomDimensions($idSite);
    }
}
