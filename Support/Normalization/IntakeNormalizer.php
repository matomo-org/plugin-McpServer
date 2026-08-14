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
 * Applies one {@see ToolIntakeProfile} to raw tool arguments and returns the canonical arguments.
 *
 * The engine knows nothing about Matomo: it moves only what the profile registers. A recovery is
 * silent; a contradiction between two locations raises an {@see ArgumentIssueException}.
 *
 * The operation order is fixed:
 *
 * 1. selector convergence, so the ways of naming one capability collapse first;
 * 2. key aliases, so later operations act on canonical destinations;
 * 3. object fields, so a serialised parameter object becomes a real object before
 *    anything is relocated into it;
 * 4. relocations and lifts between the top level and the parameter container.
 *
 * Scalar retyping is absent: integer strings are promoted for validation by
 * {@see \Piwik\Plugins\McpServer\Server\Handler\Request\CompatibleCallToolHandler} and retyped on
 * dispatch by the SDK's `ReferenceHandler::castToInt()`, both covering every tool rather than
 * only profiled ones. Comparisons here still fold the spellings, see {@see areEquivalent()}.
 *
 * Value bounds, allowlists and access checks are not applied here: canonical schema validation
 * runs on the result and domain validation after that. A parameter container is declared
 * `additionalProperties: true`, so a value relocated into one gets no per-property type check
 * from the schema - weigh that before registering a relocation in {@see ToolIntakeProfiles}.
 */
final class IntakeNormalizer
{
    public function __construct(private readonly ToolIntakeProfile $profile)
    {
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function normalize(array $arguments): array
    {
        if ($this->profile->selectorConvergence !== null) {
            $arguments = $this->profile->selectorConvergence->converge($arguments);
        }

        $arguments = $this->applyKeyAliases($arguments);
        $arguments = $this->applyObjectFields($arguments);
        $arguments = $this->applyRelocations($arguments);

        return $this->applyLifts($arguments);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function applyKeyAliases(array $arguments): array
    {
        foreach ($this->profile->keyAliases as $alias => $canonical) {
            if (!array_key_exists($alias, $arguments)) {
                continue;
            }

            $value = $arguments[$alias];
            unset($arguments[$alias]);

            if (array_key_exists($canonical, $arguments)) {
                self::assertEquivalent(
                    $arguments[$canonical],
                    $value,
                    "/{$canonical}",
                    "/{$alias}",
                    ArgumentIssueException::REASON_CONFLICTING_ALIAS_VALUES,
                );

                continue;
            }

            $arguments[$canonical] = $value;
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function applyObjectFields(array $arguments): array
    {
        foreach ($this->profile->objectFields as $field) {
            if (!array_key_exists($field, $arguments)) {
                continue;
            }

            $value = $arguments[$field];

            // An empty array is left alone here and dispatched as `[]`; the `{}` the schema asks
            // for is substituted for validation only, from these same object fields
            // ({@see \Piwik\Plugins\McpServer\Server\Handler\Request\CompatibleCallToolHandler}).
            //
            // Only a string that visibly opens as an object is decoded, so a bare word, a number
            // or a quoted scalar keeps its schema rejection.
            if (!BoundedJsonObjectDecoder::looksLikeJsonObject($value)) {
                continue;
            }

            /** @var string $value */

            $arguments[$field] = BoundedJsonObjectDecoder::decode($value, "/{$field}");
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function applyRelocations(array $arguments): array
    {
        $container = $this->profile->parameterContainer;
        if ($container === null) {
            return $arguments;
        }

        foreach ($this->profile->relocations as $source => $target) {
            if (!array_key_exists($source, $arguments)) {
                continue;
            }

            $value = $arguments[$source];
            unset($arguments[$source]);

            // `array_key_exists`, not `??`: an absent container is created here, but a supplied
            // `null` must stay a schema rejection rather than be repaired into an object.
            $supplied = array_key_exists($container, $arguments) ? $arguments[$container] : [];
            if (!self::isUsableParameterContainer($supplied)) {
                // Left for canonical schema validation to reject.
                $arguments[$source] = $value;
                continue;
            }

            /** @var array<string, mixed> $existing */
            $existing = $supplied;

            if (array_key_exists($target, $existing)) {
                self::assertEquivalent(
                    $existing[$target],
                    $value,
                    "/{$container}/{$target}",
                    "/{$source}",
                    ArgumentIssueException::REASON_CONFLICTING_PARAMETER_LOCATIONS,
                );

                continue;
            }

            $existing[$target] = $value;
            $arguments[$container] = $existing;
        }

        return $arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function applyLifts(array $arguments): array
    {
        $container = $this->profile->parameterContainer;
        if ($container === null) {
            return $arguments;
        }

        // Absent stays absent: a container the caller never sent must not be fabricated here.
        if (!array_key_exists($container, $arguments)) {
            return $arguments;
        }

        if (!self::isUsableParameterContainer($arguments[$container])) {
            return $arguments;
        }

        /** @var array<string, mixed> $parameters */
        $parameters = $arguments[$container];

        foreach ($this->profile->lifts as $source => $target) {
            if (!array_key_exists($source, $parameters)) {
                continue;
            }

            $value = $parameters[$source];
            unset($parameters[$source]);

            if (array_key_exists($target, $arguments)) {
                self::assertEquivalent(
                    $arguments[$target],
                    $value,
                    "/{$target}",
                    "/{$container}/{$source}",
                    ArgumentIssueException::REASON_CONFLICTING_PARAMETER_LOCATIONS,
                );

                continue;
            }

            $arguments[$target] = $value;
        }

        $arguments[$container] = $parameters;

        return $arguments;
    }

    /**
     * Whether a supplied parameter container can be written into or read from.
     *
     * A non-empty list is unusable: writing a string key into it produces a mixed-key array,
     * which json-encodes as an object and so passes the `type: object` check the list itself
     * fails, reindexing the caller's entries as properties. An empty list is the "no parameters"
     * shape and stays usable.
     */
    private static function isUsableParameterContainer(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * Only a decimal integer without leading zeros denotes one value unambiguously. "1.5", "01",
     * "1e3", "-0" and values beyond the platform integer range are excluded, so a caller writing
     * one of those beside a number still gets the contradiction reported.
     */
    private static function isDecimalIntegerString(string $value): bool
    {
        if (preg_match('/^-?(?:0|[1-9][0-9]*)$/', $value) !== 1) {
            return false;
        }

        return (string) (int) $value === $value;
    }

    /**
     * Reports a contradiction between two locations that were meant to say one thing.
     *
     * The message names paths only, so a segment expression or a token pasted into a parameter
     * object cannot travel back out through an argument issue.
     */
    private static function assertEquivalent(
        mixed $canonicalValue,
        mixed $suppliedValue,
        string $canonicalPath,
        string $suppliedPath,
        string $reason,
    ): void {
        if (self::areEquivalent($canonicalValue, $suppliedValue)) {
            return;
        }

        throw new ArgumentIssueException(
            $reason,
            [$suppliedPath, $canonicalPath],
            "Conflicting values supplied at '{$suppliedPath}' and '{$canonicalPath}'. "
            . 'Supply one of them, or supply equal values.',
        );
    }

    /**
     * Whether two locations say the same thing, comparing the written value rather than its JSON
     * type: `250` and `"250"`, or `true`, `1` and `"1"`, are one value spelled differently.
     *
     * This decides rejection only. The surviving value is the one already at the canonical path,
     * unchanged, and the tool's schema still validates it afterwards - a `true` folded past a
     * comparison into `filter_limit` is rejected by that field's `integer` type.
     *
     * The fold stops where Matomo's request layer stops: parameters are read as ints, where
     * `"true"` is `0`, so folding it into `true` would agree away a real disagreement.
     */
    private static function areEquivalent(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        return self::foldSpelling($a) === self::foldSpelling($b);
    }

    /**
     * Reduces the interchangeable spellings of one value to a single representation. Used for
     * comparison only; nothing is rewritten to the folded form.
     */
    private static function foldSpelling(mixed $value): mixed
    {
        if (is_bool($value)) {
            return (int) $value;
        }

        if (is_string($value) && self::isDecimalIntegerString($value)) {
            return (int) $value;
        }

        return $value;
    }
}
