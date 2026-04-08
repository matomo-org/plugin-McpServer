<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Errors;

final class NoAccessLikeErrorDetector
{
    public static function isDetected(\Throwable $e): bool
    {
        if ($e instanceof AccessDeniedLikeException || $e instanceof \Piwik\NoAccessException) {
            return true;
        }

        $message = strtolower(trim((string) $e->getMessage()));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'no access')
            || str_contains($message, 'checkuserhasviewaccess')
            || str_contains($message, 'view access');
    }
}
