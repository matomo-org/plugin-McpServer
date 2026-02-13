<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Records\Segments;

/**
 * @phpstan-type SegmentSummaryArray array{
 *     idsegment: int,
 *     name: string,
 *     definition: string,
 *     idsite: int|null,
 * }
 */
final class SegmentSummaryRecord
{
    public function __construct(
        public readonly int $idSegment,
        public readonly string $name,
        public readonly string $definition,
        public readonly ?int $idSite
    ) {
    }

    /**
     * @return SegmentSummaryArray
     */
    public function toArray(): array
    {
        return [
            'idsegment' => $this->idSegment,
            'name' => $this->name,
            'definition' => $this->definition,
            'idsite' => $this->idSite,
        ];
    }
}
