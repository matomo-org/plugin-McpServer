<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Access;

final class RawApiMethodPolicy
{
    /** @var array<string, true> */
    private const DENIED_METHODS = [
        'api.get' => true,
        'api.getbulkrequest' => true,
        'api.getmetadata' => true,
        'api.getprocessedreport' => true,
        'api.getreportmetadata' => true,
        'api.getrowevolution' => true,
        'imagegraph.get' => true,
        'insights.getinsights' => true,
        'insights.getmoversandshakers' => true,
        'treemapvisualization.gettreemapdata' => true,
    ];

    public static function allowsMethod(string $accessMode, string $method, string $action): bool
    {
        if (self::isDeniedMethod($method)) {
            return false;
        }

        if ($accessMode === RawApiAccessMode::FULL) {
            return true;
        }

        if ($accessMode !== RawApiAccessMode::READ) {
            return false;
        }

        return self::isReadHeuristicAction($action);
    }

    public static function isDeniedMethod(string $method): bool
    {
        return isset(self::DENIED_METHODS[self::normalizeSelectorValue($method)]);
    }

    public static function isReadHeuristicAction(string $action): bool
    {
        $normalizedAction = self::normalizeSelectorValue($action);

        return str_starts_with($normalizedAction, 'get')
            || str_starts_with($normalizedAction, 'is');
    }

    private static function normalizeSelectorValue(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}
