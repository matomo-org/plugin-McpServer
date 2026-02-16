<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Segments;

use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\CoreSegmentEditorGatewayInterface;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;

final class CoreSegmentEditorGateway implements CoreSegmentEditorGatewayInterface
{
    public function isSegmentEditorPluginActivated(): bool
    {
        return Manager::getInstance()->isPluginActivated('SegmentEditor');
    }

    public function getAll(int $idSite)
    {
        return SegmentEditorApi::getInstance()->getAll($idSite);
    }
}
