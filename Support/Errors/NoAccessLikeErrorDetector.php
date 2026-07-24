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

        $message = strtolower(trim($e->getMessage()));
        if ($message === '') {
            return false;
        }

        if (
            str_contains($message, 'no access')
            || str_contains($message, 'checkuserhasviewaccess')
            || str_contains($message, 'view access')
            || str_contains($message, 'access denied')
            || str_contains($message, 'permission denied')
        ) {
            return true;
        }

        if (preg_match('/\bnot authorized\b/', $message) === 1) {
            return true;
        }

        // "unauthorized" needs access context so validation wording like
        // "unauthorized character in input" does not get routed to the
        // no-access path. Allow bare "Unauthorized" replies (end-of-string
        // or trailing punctuation) and access-context nouns.
        return preg_match(
            '/\bunauthorized(?:\s+(?:access|request|user|client|api|to)\b|\s*(?=[.!?,:;)]|$))/',
            $message,
        ) === 1;
    }
}
