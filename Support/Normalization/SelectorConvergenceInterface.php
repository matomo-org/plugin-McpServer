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
 * Reduces the equivalent ways a caller may name one capability to the single canonical selector
 * the tool advertises.
 *
 * Implementations are purely syntactic: they decide whether the supplied representations agree
 * with each other, never whether the resulting selector exists. Existence and accessibility
 * belong to the access-filtered catalogue lookup that runs afterwards, so a selector surviving
 * convergence still has to match a catalogue entry exactly.
 *
 * An implementation treats as equal only what its own lookup treats as equal, which is why the
 * two implementations differ on case.
 */
interface SelectorConvergenceInterface
{
    /**
     * Upper bound in bytes on a supplied selector value, enforced before any comparison work.
     *
     * Convergence runs ahead of schema validation, so nothing else bounds a selector's length
     * here. The agreement tests compare two caller-supplied operands against each other - an
     * action searched inside a unique ID - which costs O(n*m) unless both are bounded.
     *
     * Bytes rather than characters, measured with `strlen()`, because the work being capped is
     * over bytes; the rejection message names bytes so it stays true of a multi-byte value.
     * Selectors Matomo produces are ASCII and stay well under the bound, the longest forms being
     * parameterized report IDs such as `Goals_getVisitsUntilConversion_idGoal--1234`.
     */
    public const MAX_SELECTOR_LENGTH = 256;

    /**
     * Silent on success: the arguments are rewritten and nothing is reported. A contradiction
     * between two supplied representations raises an {@see ArgumentIssueException}.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> canonical arguments
     */
    public function converge(array $arguments): array;
}
