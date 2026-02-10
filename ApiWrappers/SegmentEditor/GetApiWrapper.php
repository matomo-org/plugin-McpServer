<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\SegmentEditor;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Plugins\McpServer\Contracts\Segments\GetApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentDetailRecord;
use Piwik\Plugins\McpServer\Services\Segments\SegmentDetailQueryService;

final class GetApiWrapper implements GetApiWrapperInterface
{
    public function __construct(private ?SegmentDetailQueryServiceInterface $queryService = null)
    {
    }

    public function getSegmentBySelector(
        int $idSite,
        ?int $idSegment = null,
        ?string $name = null,
        ?string $definition = null
    ): SegmentDetailRecord {
        $segments = $this->getQueryService()->getSegmentDetailsForSite($idSite);
        $matches = array_values(array_filter(
            $segments,
            static function (SegmentDetailRecord $segment) use ($idSegment, $name, $definition): bool {
                if ($idSegment !== null) {
                    return $segment->idSegment === $idSegment;
                }

                if ($name !== null) {
                    return $segment->name === $name;
                }

                return $segment->definition === $definition;
            }
        ));

        if ($matches === []) {
            throw new ToolCallException('Segment not found.');
        }

        if (count($matches) > 1) {
            throw new ToolCallException('Multiple segments matched. Provide idSegment.');
        }

        return $matches[0];
    }

    private function getQueryService(): SegmentDetailQueryServiceInterface
    {
        return $this->queryService ??= new SegmentDetailQueryService();
    }
}
