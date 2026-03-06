<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Segments;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\CoreSegmentEditorGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Segments\SegmentSummaryRecord;
use Piwik\Plugins\McpServer\Support\Errors\ToolErrorMapper;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class SegmentSummaryQueryService implements SegmentSummaryQueryServiceInterface
{
    public function __construct(
        private CoreSegmentEditorGatewayInterface $coreSegmentEditorGateway,
        private PluginCapabilityGatewayInterface $pluginCapabilityGateway
    ) {
    }

    /**
     * @return array<int, SegmentSummaryRecord>
     */
    public function getSegmentSummariesForSite(int $idSite): array
    {
        if (!$this->pluginCapabilityGateway->isPluginActivated('SegmentEditor')) {
            throw new ToolCallException('SegmentEditor plugin is not available.');
        }

        try {
            $segments = $this->coreSegmentEditorGateway->getAll($idSite);
        } catch (\Throwable $e) {
            // Keep segment list behavior aligned with site list: no view access yields no rows.
            if (ToolErrorMapper::shouldReturnEmptyListFor($e)) {
                return [];
            }

            throw new ToolCallException('Segment retrieval failed.');
        }

        return $this->normalizeSegmentSummaryRows(
            $segments,
            'Segment list data is invalid.',
            'Segment list item'
        );
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $segment
     */
    public function normalizeSegmentSummaryData(array $segment, string $context): SegmentSummaryRecord
    {
        $segmentSiteId = ToolDataNormalizer::requireIntLikeField($segment, 'enable_only_idsite', $context);

        return new SegmentSummaryRecord(
            idSegment: ToolDataNormalizer::requireIntLikeField($segment, 'idsegment', $context),
            name: ToolDataNormalizer::requireStringField($segment, 'name', $context),
            definition: ToolDataNormalizer::requireStringField($segment, 'definition', $context),
            idSite: $segmentSiteId === 0 ? null : $segmentSiteId,
        );
    }

    /**
     * Public for testability and to keep top-level payload-shape validation centralized.
     *
     * @return array<int, SegmentSummaryRecord>
     */
    public function normalizeSegmentSummaryRows(
        mixed $segments,
        string $invalidDataMessage,
        string $context
    ): array {
        if (!is_array($segments)) {
            throw new ToolCallException($invalidDataMessage);
        }

        $result = [];
        foreach ($segments as $segment) {
            $segmentData = ToolDataNormalizer::requireStringKeyedArray($segment, $invalidDataMessage);
            $result[] = $this->normalizeSegmentSummaryData($segmentData, $context);
        }

        return $result;
    }
}
