<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Segments;

use Piwik\Plugins\McpServer\Contracts\Records\Segments\SegmentDetailRecord;

interface SegmentDetailQueryServiceInterface
{
    /**
     * @return array<int, SegmentDetailRecord>
     */
    public function getSegmentDetailsForSite(int $idSite): array;

    public function getSegmentBySelector(
        int $idSite,
        ?int $idSegment = null,
        ?string $name = null,
        ?string $definition = null,
    ): SegmentDetailRecord;
}
