<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Dimensions;

use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\CoreCustomDimensionsGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Dimensions\DimensionDetailRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Errors\ToolErrorMapper;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class DimensionDetailQueryService implements DimensionDetailQueryServiceInterface
{
    public function __construct(
        private CoreCustomDimensionsGatewayInterface $coreCustomDimensionsGateway,
        private PluginCapabilityGatewayInterface $pluginCapabilityGateway,
    ) {
    }

    public function getDimensionDetailForSite(int $idSite, int $idDimension): DimensionDetailRecord
    {
        if (!$this->pluginCapabilityGateway->isPluginActivated('CustomDimensions')) {
            throw new McpToolCallException('CustomDimensions plugin is not available.');
        }

        try {
            $dimensions = $this->coreCustomDimensionsGateway->getConfiguredCustomDimensions($idSite);
        } catch (\Throwable $e) {
            ToolErrorMapper::throwDetailFailure(
                $e,
                'Dimension not found.',
                'Dimension retrieval failed.',
                static fn(\Throwable $error): bool => ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()
            );
        }

        foreach ($dimensions as $dimension) {
            $dimensionData = ToolDataNormalizer::requireStringKeyedArray($dimension, 'Dimension data');

            $candidateId = ToolDataNormalizer::requireIntLikeField(
                $dimensionData,
                'idcustomdimension',
                'Dimension data',
            );

            if ($candidateId !== $idDimension) {
                continue;
            }

            return $this->normalizeDimensionDetailData($dimensionData, 'Dimension data');
        }

        throw new McpToolCallException('Dimension not found.');
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $dimension
     */
    public function normalizeDimensionDetailData(array $dimension, string $context): DimensionDetailRecord
    {
        return new DimensionDetailRecord(
            idDimension: ToolDataNormalizer::requireIntLikeField($dimension, 'idcustomdimension', $context),
            idSite: ToolDataNormalizer::requireIntLikeField($dimension, 'idsite', $context),
            name: ToolDataNormalizer::requireStringField($dimension, 'name', $context),
            index: ToolDataNormalizer::requireIntLikeField($dimension, 'index', $context),
            scope: ToolDataNormalizer::requireStringField($dimension, 'scope', $context),
            active: ToolDataNormalizer::requireBoolLikeField($dimension, 'active', $context),
            caseSensitive: ToolDataNormalizer::requireBoolLikeField($dimension, 'case_sensitive', $context),
            extractions: $this->normalizeExtractions($dimension['extractions'] ?? [], $context),
        );
    }

    /**
     * @return list<array{dimension: string, pattern: string}>
     */
    private function normalizeExtractions(mixed $extractions, string $context): array
    {
        if (!is_array($extractions)) {
            throw new McpToolCallException("{$context} is invalid (field 'extractions').");
        }

        $result = [];
        foreach ($extractions as $extraction) {
            $extractionData = ToolDataNormalizer::requireStringKeyedArray(
                $extraction,
                "{$context} is invalid (field 'extractions')",
            );

            $result[] = [
                'dimension' => ToolDataNormalizer::requireStringField($extractionData, 'dimension', $context),
                'pattern' => ToolDataNormalizer::requireStringField($extractionData, 'pattern', $context),
            ];
        }

        return $result;
    }
}
