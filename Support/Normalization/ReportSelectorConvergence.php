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
 * Canonicalises the individual selector forms accepted by the report tools.
 *
 * Three recoveries are registered, and nothing else:
 *
 * - surrounding whitespace on a selector value is dropped;
 * - a `Module.action` value in `reportUniqueId` is rewritten to `Module_action`. The dot form
 *   already resolves - `CoreProcessedReportGateway` derives each report's dotted alias from its
 *   metadata, covering the forms {@see convertDotForm()} declines;
 * - surrounding whitespace on a complete `apiModule`/`apiAction` pair is dropped.
 *
 * There is no edit distance, plural correction or nearest-report selection: `Pages_getPageTitles`
 * stays unresolved instead of becoming `Actions_getPageTitles`, and a rewritten ID still has to
 * match an accessible catalogue entry exactly.
 *
 * An `apiModule`/`apiAction` pair supplied beside `reportUniqueId` is also left alone, so the
 * canonical schema rejects the combination. An underscore unique ID does not encode an
 * authoritative module/action boundary: `Goals_get_idGoal--1` can be split textually as
 * `Goals_get` + `idGoal--1`, even though its report metadata says `Goals` + `get`. Only the
 * access-filtered report catalogue can decide semantic agreement, and intake convergence must
 * not guess before that lookup.
 */
final class ReportSelectorConvergence implements SelectorConvergenceInterface
{
    private const UNIQUE_ID_KEY = 'reportUniqueId';

    public function converge(array $arguments): array
    {
        $arguments = $this->convergeUniqueId($arguments);

        return $this->convergeModuleAndAction($arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function convergeUniqueId(array $arguments): array
    {
        $value = self::readSelector($arguments, self::UNIQUE_ID_KEY);
        if ($value === null) {
            return $arguments;
        }

        $arguments[self::UNIQUE_ID_KEY] = self::convertDotForm($value);

        return $arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function convergeModuleAndAction(array $arguments): array
    {
        $uniqueId = self::readSelector($arguments, self::UNIQUE_ID_KEY);
        if ($uniqueId !== null) {
            // Preserve every companion for the schema to reject. Textual agreement cannot prove
            // that the values name the same report without consulting report metadata.
            return $arguments;
        }

        // The pair is the surviving selector, and the metadata lookup compares module and action
        // exactly, so it needs the same whitespace canonicalisation the unique ID gets.
        return $this->trimSurvivingModuleAndAction($arguments);
    }

    /**
     * Canonicalises the whitespace of an `apiModule`/`apiAction` pair supplied without a unique
     * ID, the only case where those keys reach the lookup themselves.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function trimSurvivingModuleAndAction(array $arguments): array
    {
        $module = self::readSelector($arguments, 'apiModule');
        $action = self::readSelector($arguments, 'apiAction');

        // Both halves or neither: a lone `apiModule` or `apiAction` is an incomplete selector the
        // schema rejects whatever its whitespace.
        if ($module === null || $action === null) {
            return $arguments;
        }

        $arguments['apiModule'] = $module;
        $arguments['apiAction'] = $action;

        return $arguments;
    }

    /**
     * Rewrites `Module.action` to `Module_action`, and nothing else.
     *
     * The module segment may contain neither an underscore nor interior whitespace, which keeps
     * the separator unambiguous: `Actions_getPageUrls.extra` and `Actions . getPageUrls` are left
     * alone. The action segment may contain underscores, everything after the dot being one token,
     * so `Goals.get_idGoal--1` converts.
     *
     * Declining `My_Plugin.get` costs nothing, since the gateway's metadata-derived alias resolves
     * it.
     */
    private static function convertDotForm(string $value): string
    {
        if (preg_match('/^([^._\s]+)\.([^.\s]+)$/', $value, $matches) !== 1) {
            return $value;
        }

        return $matches[1] . '_' . $matches[2];
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
}
