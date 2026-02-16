<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Reports;

use Piwik\ArchiveProcessor\Rules;
use Piwik\Period;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\StrictSegmentPolicyServiceInterface;
use Piwik\Segment;
use Piwik\Site;

final class StrictSegmentPolicyService implements StrictSegmentPolicyServiceInterface
{
    public function shouldMapToStrictSegmentGuidance(
        int $idSite,
        string $period,
        string $date,
        ?string $segment
    ): bool {
        if ($segment === null || trim($segment) === '') {
            return false;
        }

        if (!$this->isRuntimeSegmentProcessingUnavailable()) {
            return false;
        }

        try {
            return !$this->isSegmentPreprocessedForReportRequest($idSite, $period, $date, $segment);
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function isRuntimeSegmentProcessingUnavailable(): bool
    {
        return !Rules::isRequestAuthorizedToArchive() && !Rules::isBrowserArchivingAvailableForSegments();
    }

    private function isSegmentPreprocessedForReportRequest(
        int $idSite,
        string $period,
        string $date,
        string $segment
    ): bool {
        $site = new Site($idSite);
        $resolvedPeriod = Period\Factory::build($period, $date);
        $resolvedSegment = new Segment(
            $segment,
            [$idSite],
            $resolvedPeriod->getDateTimeStart()->setTimezone($site->getTimezone()),
            $resolvedPeriod->getDateTimeEnd()->setTimezone($site->getTimezone())
        );

        return Rules::isSegmentPreProcessed([$idSite], $resolvedSegment);
    }
}
