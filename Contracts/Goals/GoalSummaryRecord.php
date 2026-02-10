<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Goals;

/**
 * @phpstan-type GoalSummaryArray array{
 *     idgoal: int,
 *     idsite: int,
 *     name: string,
 *     description: string,
 *     match_attribute: string,
 *     allow_multiple: bool,
 *     revenue: string,
 *     event_value_as_revenue: bool,
 * }
 */
final class GoalSummaryRecord
{
    public function __construct(
        public readonly int $idGoal,
        public readonly int $idSite,
        public readonly string $name,
        public readonly string $description,
        public readonly string $matchAttribute,
        public readonly bool $allowMultiple,
        public readonly string $revenue,
        public readonly bool $eventValueAsRevenue
    ) {
    }

    /**
     * @return GoalSummaryArray
     */
    public function toArray(): array
    {
        return [
            'idgoal' => $this->idGoal,
            'idsite' => $this->idSite,
            'name' => $this->name,
            'description' => $this->description,
            'match_attribute' => $this->matchAttribute,
            'allow_multiple' => $this->allowMultiple,
            'revenue' => $this->revenue,
            'event_value_as_revenue' => $this->eventValueAsRevenue,
        ];
    }
}
