<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Dimensions;

/**
 * @phpstan-type DimensionExtractionArray array{
 *     dimension: string,
 *     pattern: string,
 * }
 *
 * @phpstan-type DimensionDetailArray array{
 *     iddimension: int,
 *     idsite: int,
 *     name: string,
 *     index: int,
 *     scope: string,
 *     active: bool,
 *     case_sensitive: bool,
 *     extractions: list<DimensionExtractionArray>,
 * }
 */
final class DimensionDetailRecord
{
    /**
     * @param list<DimensionExtractionArray> $extractions
     */
    public function __construct(
        public readonly int $idDimension,
        public readonly int $idSite,
        public readonly string $name,
        public readonly int $index,
        public readonly string $scope,
        public readonly bool $active,
        public readonly bool $caseSensitive,
        public readonly array $extractions
    ) {
    }

    /**
     * @return DimensionDetailArray
     */
    public function toArray(): array
    {
        return [
            'iddimension' => $this->idDimension,
            'idsite' => $this->idSite,
            'name' => $this->name,
            'index' => $this->index,
            'scope' => $this->scope,
            'active' => $this->active,
            'case_sensitive' => $this->caseSensitive,
            'extractions' => $this->extractions,
        ];
    }
}
