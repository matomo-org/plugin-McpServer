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
use Piwik\NoAccessException;
use Piwik\Plugin\Manager;
use Piwik\Plugins\CustomDimensions\API as CustomDimensionsApi;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Dimensions\DimensionDetailRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class DimensionDetailQueryService implements DimensionDetailQueryServiceInterface
{
    public function getDimensionDetailForSite(int $idSite, int $idDimension): DimensionDetailRecord
    {
        if (!Manager::getInstance()->isPluginActivated('CustomDimensions')) {
            throw new ToolCallException('CustomDimensions plugin is not available.');
        }

        try {
            $dimensions = CustomDimensionsApi::getInstance()->getConfiguredCustomDimensions($idSite);
        } catch (NoAccessException $e) {
            throw new ToolCallException('Dimension not found.');
        } catch (\Throwable $e) {
            if (ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
                throw new ToolCallException('Dimension not found.');
            }

            throw new ToolCallException('Dimension retrieval failed.');
        }

        foreach ($dimensions as $dimension) {
            $dimensionData = ToolDataNormalizer::requireStringKeyedArray($dimension, 'Dimension data');

            $candidateId = ToolDataNormalizer::requireIntLikeField(
                $dimensionData,
                'idcustomdimension',
                'Dimension data'
            );

            if ($candidateId !== $idDimension) {
                continue;
            }

            return $this->normalizeDimensionDetailData($dimensionData, 'Dimension data');
        }

        throw new ToolCallException('Dimension not found.');
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
     * @param mixed $extractions
     * @return list<array{dimension: string, pattern: string}>
     */
    private function normalizeExtractions(mixed $extractions, string $context): array
    {
        if (!is_array($extractions)) {
            throw new ToolCallException("{$context} is invalid (field 'extractions').");
        }

        $result = [];
        foreach ($extractions as $extraction) {
            $extractionData = ToolDataNormalizer::requireStringKeyedArray(
                $extraction,
                "{$context} is invalid (field 'extractions')"
            );

            $result[] = [
                'dimension' => ToolDataNormalizer::requireStringField($extractionData, 'dimension', $context),
                'pattern' => ToolDataNormalizer::requireStringField($extractionData, 'pattern', $context),
            ];
        }

        return $result;
    }
}
