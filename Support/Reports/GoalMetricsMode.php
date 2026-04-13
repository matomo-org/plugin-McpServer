<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Reports;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\DataTable\Filter\AddColumnsProcessedMetricsGoal;
use Piwik\Piwik;

enum GoalMetricsMode: string
{
    case OVERVIEW = 'overview';
    case FULL = 'full';
    case MINIMAL = 'minimal';
    case PAGES = 'pages';
    case ENTRY_PAGES = 'entry_pages';
    case PAGES_ECOMMERCE = 'pages_ecommerce';
    case ENTRY_PAGES_ECOMMERCE = 'entry_pages_ecommerce';
    case SPECIFIC_GOAL = 'specific_goal';
    case ECOMMERCE_ORDER = 'ecommerce_order';
    case ECOMMERCE_CART = 'ecommerce_cart';

    public const SCHEMA_VALUES = [
        'overview',
        'full',
        'minimal',
        'pages',
        'entry_pages',
        'pages_ecommerce',
        'entry_pages_ecommerce',
        'specific_goal',
        'ecommerce_order',
        'ecommerce_cart',
    ];

    public static function fromInput(string $value): self
    {
        return self::tryFrom($value) ?? throw new ToolCallException(
            "Invalid goalMetricsMode value '{$value}'. Allowed values: " . implode(', ', self::SCHEMA_VALUES) . '.',
        );
    }

    public function toCoreFilterValue(int|string|null $idGoal): string
    {
        return match ($this) {
            self::OVERVIEW => (string) AddColumnsProcessedMetricsGoal::GOALS_OVERVIEW,
            self::FULL => (string) AddColumnsProcessedMetricsGoal::GOALS_FULL_TABLE,
            self::MINIMAL => (string) AddColumnsProcessedMetricsGoal::GOALS_MINIMAL_REPORT,
            self::PAGES => (string) AddColumnsProcessedMetricsGoal::GOALS_PAGES,
            self::ENTRY_PAGES => (string) AddColumnsProcessedMetricsGoal::GOALS_ENTRY_PAGES,
            self::PAGES_ECOMMERCE => (string) AddColumnsProcessedMetricsGoal::GOALS_PAGES_ECOMMERCE,
            self::ENTRY_PAGES_ECOMMERCE => (string) AddColumnsProcessedMetricsGoal::GOALS_ENTRY_PAGES_ECOMMERCE,
            self::ECOMMERCE_ORDER => Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_ORDER,
            self::ECOMMERCE_CART => Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_CART,
            self::SPECIFIC_GOAL => self::normalizeSpecificGoal($idGoal),
        };
    }

    private static function normalizeSpecificGoal(int|string|null $idGoal): string
    {
        if (is_int($idGoal) && $idGoal > 0) {
            return (string) $idGoal;
        }

        if (is_string($idGoal)) {
            $normalized = trim($idGoal);
            if (preg_match('/^[1-9][0-9]*$/', $normalized) === 1) {
                return $normalized;
            }

            if (self::isCoreEcommerceGoalId($normalized)) {
                return $normalized;
            }
        }

        throw new ToolCallException(
            "goalMetricsMode '" . self::SPECIFIC_GOAL->value . "' requires idGoal to be a positive integer "
            . "or one of: " . Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_ORDER . ', '
            . Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_CART . '.',
        );
    }

    public static function isCoreEcommerceGoalId(string $value): bool
    {
        return $value === Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_ORDER
            || $value === Piwik::LABEL_ID_GOAL_IS_ECOMMERCE_CART;
    }
}
