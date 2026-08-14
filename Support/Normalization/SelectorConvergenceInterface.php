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
     * Silent on success: the arguments are rewritten and nothing is reported. A contradiction
     * between two supplied representations raises an {@see ArgumentIssueException}.
     *
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> canonical arguments
     */
    public function converge(array $arguments): array;
}
