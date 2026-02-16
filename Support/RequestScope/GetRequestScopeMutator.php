<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\RequestScope;

final class GetRequestScopeMutator implements GetRequestScopeMutatorInterface
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function runWithParameters(array $parameters, callable $callback): mixed
    {
        $previousGet = $_GET;
        $previousRequest = $_REQUEST;

        foreach ($parameters as $key => $value) {
            $_GET[$key] = $value;
            $_REQUEST[$key] = $value;
        }

        try {
            return $callback();
        } finally {
            $_GET = $previousGet;
            $_REQUEST = $previousRequest;
        }
    }
}
