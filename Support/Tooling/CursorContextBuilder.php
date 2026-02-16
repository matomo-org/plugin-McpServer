<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Tooling;

final class CursorContextBuilder
{
    /**
     * @param array<string, scalar|null> $scope
     */
    public static function forTool(string $toolName, array $scope = []): string
    {
        ksort($scope);

        $payload = [
            'tool' => $toolName,
            'scope' => $scope,
        ];

        $source = json_encode($payload, JSON_THROW_ON_ERROR);
        return hash('sha256', $source);
    }
}
