<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\System;

use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;

final class PluginCapabilityGateway implements PluginCapabilityGatewayInterface
{
    public function isPluginActivated(string $pluginName): bool
    {
        return Manager::getInstance()->isPluginActivated($pluginName);
    }
}
