<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Dimensions;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Plugins\CustomDimensions\API as CustomDimensionsApi;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\CoreCustomDimensionsGatewayInterface;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class CoreCustomDimensionsGateway implements CoreCustomDimensionsGatewayInterface
{
    public function getConfiguredCustomDimensions(int $idSite): array
    {
        $dimensions = CustomDimensionsApi::getInstance()->getConfiguredCustomDimensions($idSite);

        return $this->normalizeRows($dimensions, 'Custom dimensions data is invalid.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(mixed $rows, string $invalidDataMessage): array
    {
        if (!is_array($rows)) {
            throw new ToolCallException($invalidDataMessage);
        }

        if (!array_is_list($rows)) {
            $rows = array_values($rows);
        }

        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = ToolDataNormalizer::requireStringKeyedArray($row, $invalidDataMessage);
        }

        return $normalized;
    }
}
