<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Errors;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;

final class ToolErrorMapper
{
    /**
     * @param callable(\Throwable): bool|null $isNoAccessLike
     */
    public static function shouldReturnEmptyListFor(
        \Throwable $e,
        ?callable $isNoAccessLike = null,
        bool $allowGlobalFallback = true
    ): bool {
        if ($e instanceof NoAccessException || $e instanceof AccessDeniedLikeException) {
            return true;
        }

        if (
            $isNoAccessLike !== null
            && $isNoAccessLike($e)
            && ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()
        ) {
            return true;
        }

        if ($allowGlobalFallback && ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback()) {
            return true;
        }

        return false;
    }

    /**
     * @param callable(\Throwable): bool|null $isNotFoundOrNoAccessLike
     */
    public static function throwDetailFailure(
        \Throwable $e,
        string $notFoundMessage,
        string $failedMessage,
        ?callable $isNotFoundOrNoAccessLike = null
    ): never {
        if (
            $e instanceof NoAccessException
            || $e instanceof AccessDeniedLikeException
            || $e instanceof NotFoundLikeException
        ) {
            throw new ToolCallException($notFoundMessage);
        }

        if ($isNotFoundOrNoAccessLike !== null && $isNotFoundOrNoAccessLike($e)) {
            throw new ToolCallException($notFoundMessage);
        }

        throw new ToolCallException($failedMessage);
    }
}
