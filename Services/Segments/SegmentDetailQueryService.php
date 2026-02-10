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
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentDetailRecord;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;

final class SegmentDetailQueryService implements SegmentDetailQueryServiceInterface
{
    /**
     * @return array<int, SegmentDetailRecord>
     */
    public function getSegmentDetailsForSite(int $idSite): array
    {
        if (!Manager::getInstance()->isPluginActivated('SegmentEditor')) {
            throw new ToolCallException('SegmentEditor plugin is not available.');
        }

        try {
            $segments = SegmentEditorApi::getInstance()->getAll($idSite);
        } catch (NoAccessException $e) {
            throw new ToolCallException('Segment not found.');
        } catch (\Throwable $e) {
            throw new ToolCallException('Segment retrieval failed.');
        }

        return $this->normalizeSegmentDetailRows(
            $segments,
            'Segment detail data is invalid.',
            'Segment detail item'
        );
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $segment
     */
    public function normalizeSegmentDetailData(array $segment, string $context): SegmentDetailRecord
    {
        $segmentSiteId = ToolDataNormalizer::requireIntLikeField($segment, 'enable_only_idsite', $context);

        return new SegmentDetailRecord(
            idSegment: ToolDataNormalizer::requireIntLikeField($segment, 'idsegment', $context),
            name: ToolDataNormalizer::requireStringField($segment, 'name', $context),
            definition: ToolDataNormalizer::requireStringField($segment, 'definition', $context),
            idSite: $segmentSiteId === 0 ? null : $segmentSiteId,
            autoArchive: ToolDataNormalizer::requireBoolLikeField($segment, 'auto_archive', $context),
            enabledAllUsers: ToolDataNormalizer::requireBoolLikeField($segment, 'enable_all_users', $context),
            login: ToolDataNormalizer::requireStringField($segment, 'login', $context),
        );
    }

    /**
     * Public for testability and to keep top-level payload-shape validation centralized.
     *
     * @param mixed $segments
     * @return array<int, SegmentDetailRecord>
     */
    public function normalizeSegmentDetailRows(
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
            $result[] = $this->normalizeSegmentDetailData($segment, $context);
        }

        return $result;
    }
}
