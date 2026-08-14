<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Normalization;

/**
 * Raised when intake arguments cannot be reduced to one canonical request.
 *
 * Messages name the JSON pointers involved, never their values, which may be segment
 * expressions or tokens.
 *
 * {@see \Piwik\Plugins\McpServer\Server\Handler\Request\CompatibleCallToolHandler} returns
 * this as an `isError` tool result.
 */
final class ArgumentIssueException extends \RuntimeException
{
    public const REASON_CONFLICTING_ALIAS_VALUES = 'conflicting_alias_values';
    public const REASON_CONFLICTING_SELECTORS = 'conflicting_selectors';
    public const REASON_CONFLICTING_PARAMETER_LOCATIONS = 'conflicting_parameter_locations';
    public const REASON_INVALID_SELECTOR_SYNTAX = 'invalid_selector_syntax';
    public const REASON_INVALID_JSON_OBJECT = 'invalid_json_object';

    /**
     * @param list<string> $paths
     */
    public function __construct(
        public readonly string $reason,
        public readonly array $paths,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
