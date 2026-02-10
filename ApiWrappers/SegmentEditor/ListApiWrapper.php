<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\SegmentEditor;

use Piwik\Plugins\McpServer\Contracts\Segments\ListApiWrapperInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Segments\SegmentSummaryRecord;
use Piwik\Plugins\McpServer\Services\Segments\SegmentSummaryQueryService;

final class ListApiWrapper implements ListApiWrapperInterface
{
    public function __construct(private ?SegmentSummaryQueryServiceInterface $queryService = null)
    {
    }

    /**
     * @return array<int, SegmentSummaryRecord>
     */
    public function getSegmentsForSite(int $idSite): array
    {
        return $this->getQueryService()->getSegmentSummariesForSite($idSite);
    }

    private function getQueryService(): SegmentSummaryQueryServiceInterface
    {
        return $this->queryService ??= new SegmentSummaryQueryService();
    }
}
