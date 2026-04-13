<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

final class McpEndpointGuard
{
    public function validate(
        string $format,
        string $module,
        string $method,
        bool $isRootApiRequest,
        string $rootApiMethod,
    ): ?string {
        if (
            $format !== McpEndpointSpec::FORMAT
            || $module !== McpEndpointSpec::MODULE
            || $method !== McpEndpointSpec::METHOD
            || !$isRootApiRequest
            || $rootApiMethod !== McpEndpointSpec::METHOD
        ) {
            return McpEndpointSpec::GUARD_ERROR;
        }

        return null;
    }
}
