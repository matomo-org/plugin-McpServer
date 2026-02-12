<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Records\Goals;

/**
 * @phpstan-type GoalDetailArray array{
 *     idgoal: int,
 *     idsite: int,
 *     name: string,
 *     description: string,
 *     match_attribute: string,
 *     allow_multiple: bool,
 *     revenue: string,
 *     event_value_as_revenue: bool,
 *     pattern: string|null,
 *     pattern_type: string|null,
 *     case_sensitive: bool|null,
 * }
 */
final class GoalDetailRecord
{
    public function __construct(
        public readonly int $idGoal,
        public readonly int $idSite,
        public readonly string $name,
        public readonly string $description,
        public readonly string $matchAttribute,
        public readonly bool $allowMultiple,
        public readonly string $revenue,
        public readonly bool $eventValueAsRevenue,
        public readonly ?string $pattern,
        public readonly ?string $patternType,
        public readonly ?bool $caseSensitive
    ) {
    }

    /**
     * @return GoalDetailArray
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
            'pattern' => $this->pattern,
            'pattern_type' => $this->patternType,
            'case_sensitive' => $this->caseSensitive,
        ];
    }
}
