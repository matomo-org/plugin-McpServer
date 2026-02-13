<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Records\Dimensions;

/**
 * @phpstan-type DimensionSummaryArray array{
 *     iddimension: int,
 *     name: string,
 *     scope: string,
 * }
 */
final class DimensionSummaryRecord
{
    public function __construct(
        public readonly int $idDimension,
        public readonly string $name,
        public readonly string $scope
    ) {
    }

    /**
     * @return DimensionSummaryArray
     */
    public function toArray(): array
    {
        return [
            'iddimension' => $this->idDimension,
            'name' => $this->name,
            'scope' => $this->scope,
        ];
    }
}
