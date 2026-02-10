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
use Piwik\NoAccessException;
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentSummaryRecord;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;

final class SegmentSummaryQueryService implements SegmentSummaryQueryServiceInterface
{
    /**
     * @return array<int, SegmentSummaryRecord>
     */
    public function getSegmentSummariesForSite(int $idSite): array
    {
        if (!Manager::getInstance()->isPluginActivated('SegmentEditor')) {
            throw new ToolCallException('SegmentEditor plugin is not available.');
        }

        try {
            $segments = SegmentEditorApi::getInstance()->getAll($idSite);
        } catch (NoAccessException $e) {
            // Keep segment list behavior aligned with site list: no view access yields no rows.
            return [];
        } catch (\Throwable $e) {
            if (ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
                // Compatibility fallback for no-access backends that do not throw NoAccessException.
                return [];
            }

            throw $e;
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
     * @param mixed $segments
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
            if (!is_array($segment)) {
                throw new ToolCallException($invalidDataMessage);
            }
            $result[] = $this->normalizeSegmentSummaryData($segment, $context);
        }

        return $result;
    }
}
