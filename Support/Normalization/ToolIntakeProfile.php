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
 * The per-tool allowlist of accepted alternate input representations.
 *
 * Nothing is inferred: an unregistered lookalike such as `filter_Limit` stays invalid and
 * surfaces the schema error. There is no generic camelCase conversion either, `idSite`,
 * `idSubtable` and `pageUrl` being canonical Matomo spellings.
 */
final class ToolIntakeProfile
{
    /**
     * @param array<string, string> $keyAliases
     * @param list<string> $objectFields
     * @param array<string, string> $relocations
     * @param array<string, string> $lifts
     */
    public function __construct(
        public readonly array $keyAliases = [],
        public readonly array $objectFields = [],
        public readonly ?string $parameterContainer = null,
        public readonly array $relocations = [],
        public readonly array $lifts = [],
        public readonly ?SelectorConvergenceInterface $selectorConvergence = null,
    ) {
    }
}
