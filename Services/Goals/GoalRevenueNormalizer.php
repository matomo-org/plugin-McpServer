<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Goals;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;

final class GoalRevenueNormalizer
{
    /**
     * @param array<string, mixed> $goal
     */
    public static function normalizeRevenue(array $goal, string $context): string
    {
        if (!array_key_exists('revenue', $goal) || $goal['revenue'] === null) {
            throw new ToolCallException("{$context} is incomplete (missing 'revenue').");
        }

        $revenue = $goal['revenue'];
        if (is_string($revenue)) {
            return $revenue;
        }

        if (is_int($revenue) || is_float($revenue)) {
            return (string) $revenue;
        }

        throw new ToolCallException("{$context} is invalid (field 'revenue').");
    }
}
