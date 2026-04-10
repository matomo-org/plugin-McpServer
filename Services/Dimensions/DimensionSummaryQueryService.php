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
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\CoreCustomDimensionsGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Dimensions\DimensionSummaryRecord;
use Piwik\Plugins\McpServer\Support\Errors\ToolErrorMapper;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class DimensionSummaryQueryService implements DimensionSummaryQueryServiceInterface
{
    public function __construct(
        private CoreCustomDimensionsGatewayInterface $coreCustomDimensionsGateway,
        private PluginCapabilityGatewayInterface $pluginCapabilityGateway,
    ) {
    }

    /**
     * @return array<int, DimensionSummaryRecord>
     */
    public function getDimensionSummariesForSite(int $idSite): array
    {
        if (!$this->pluginCapabilityGateway->isPluginActivated('CustomDimensions')) {
            throw new ToolCallException('CustomDimensions plugin is not available.');
        }

        try {
            $dimensions = $this->coreCustomDimensionsGateway->getConfiguredCustomDimensions($idSite);
        } catch (\Throwable $e) {
            // Keep list behavior aligned with site/segment/goal list: no view access yields no rows.
            if (ToolErrorMapper::shouldReturnEmptyListFor($e)) {
                return [];
            }

            throw new ToolCallException('Dimension retrieval failed.');
        }

        return $this->normalizeDimensionSummaryRows(
            $dimensions,
            'Dimension list data is invalid.',
            'Dimension list item',
        );
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $dimension
     */
    public function normalizeDimensionSummaryData(array $dimension, string $context): DimensionSummaryRecord
    {
        return new DimensionSummaryRecord(
            idDimension: ToolDataNormalizer::requireIntLikeField($dimension, 'idcustomdimension', $context),
            name: ToolDataNormalizer::requireStringField($dimension, 'name', $context),
            scope: ToolDataNormalizer::requireStringField($dimension, 'scope', $context),
        );
    }

    /**
     * Public for testability and to keep top-level payload-shape validation centralized.
     *
     * @return array<int, DimensionSummaryRecord>
     */
    public function normalizeDimensionSummaryRows(
        mixed $dimensions,
        string $invalidDataMessage,
        string $context,
    ): array {
        if (!is_array($dimensions)) {
            throw new ToolCallException($invalidDataMessage);
        }

        $result = [];
        foreach ($dimensions as $dimension) {
            $dimensionData = ToolDataNormalizer::requireStringKeyedArray($dimension, $invalidDataMessage);

            $isActive = ToolDataNormalizer::requireBoolLikeField($dimensionData, 'active', $context);
            if (!$isActive) {
                continue;
            }

            $normalized = $this->normalizeDimensionSummaryData($dimensionData, $context);
            $result[] = $normalized;
        }

        return $result;
    }
}
