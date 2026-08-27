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
 * Collapses the `method`, `module` and `action` inputs of the raw API tools into the canonical
 * `method` selector.
 *
 * A `module` or `action` matching the supplied `method` is dropped as redundant; a differing one
 * is rejected, not resolved. A `module` + `action` pair without `method` is a selector form the
 * tools accept and is left as supplied.
 *
 * Comparison folds case and surrounding whitespace, matching
 * {@see \Piwik\Plugins\McpServer\Services\Api\ApiMethodSummaryQueryService}, which compares
 * `strtolower(trim())` and decides existence, access and operation category afterwards.
 *
 * The report unique-ID spelling (`VisitsSummary_get`) is not repaired: a module name may contain
 * an underscore, so `My_Plugin_get` has no unambiguous split. {@see ReportSelectorConvergence}
 * converts the dot form, which a module name never contains.
 */
final class ApiSelectorConvergence implements SelectorConvergenceInterface
{
    public function converge(array $arguments): array
    {
        $method = self::readSelector($arguments, 'method');
        $module = self::readSelector($arguments, 'module');
        $action = self::readSelector($arguments, 'action');

        if ($method === null) {
            // An incomplete selector, or the `module` + `action` form. Neither needs recovery.
            return $arguments;
        }

        [$methodModule, $methodAction] = self::parseMethod($method);

        // Only the parts readSelector() accepted are consumed; a non-string or empty `module` or
        // `action` stays in place for the canonical schema to report.
        if ($module !== null) {
            self::assertSelectorPartsAgree($methodModule, $module, '/method', '/module');
            unset($arguments['module']);
        }
        if ($action !== null) {
            self::assertSelectorPartsAgree($methodAction, $action, '/method', '/action');
            unset($arguments['action']);
        }

        return $arguments;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function parseMethod(string $method): array
    {
        // Interior whitespace is rejected rather than trimmed, because the lookup trims the
        // method as a whole: `VisitsSummary. get` would otherwise agree with `module`, lose it
        // as redundant, and then resolve to nothing.
        if (preg_match('/^([^.\s]+)\.([^.\s]+)$/', $method, $matches) !== 1) {
            throw new ArgumentIssueException(
                ArgumentIssueException::REASON_INVALID_SELECTOR_SYNTAX,
                ['/method'],
                "Value at '/method' must be an exact Matomo API method name in the form Module.action.",
            );
        }

        return [$matches[1], $matches[2]];
    }

    private static function assertSelectorPartsAgree(
        string $fromMethod,
        string $supplied,
        string $methodPath,
        string $suppliedPath,
    ): void {
        if (self::normalize($fromMethod) === self::normalize($supplied)) {
            return;
        }

        throw new ArgumentIssueException(
            ArgumentIssueException::REASON_CONFLICTING_SELECTORS,
            [$methodPath, $suppliedPath],
            "Conflicting API selectors supplied at '{$methodPath}' and '{$suppliedPath}'. "
            . 'Supply one selector form, or supply matching values.',
        );
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function readSelector(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;
        if (!is_string($value)) {
            return null;
        }

        // Bounded on the raw value, before trim() copies it: convergence precedes schema
        // validation, so this is the only length gate.
        // See {@see SelectorConvergenceInterface::MAX_SELECTOR_LENGTH}.
        if (strlen($value) > self::MAX_SELECTOR_LENGTH) {
            throw new ArgumentIssueException(
                ArgumentIssueException::REASON_SELECTOR_TOO_LONG,
                ["/{$key}"],
                "Value at '/{$key}' must be at most " . self::MAX_SELECTOR_LENGTH . ' bytes.',
            );
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private static function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
