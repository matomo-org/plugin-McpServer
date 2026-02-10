<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Pagination;

use InvalidArgumentException;

final class PaginationConfig
{
    public readonly int $defaultLimit;
    public readonly int $maxLimit;
    public readonly string $defaultSortToken;
    /**
     * @var array<int, SortSpec>
     */
    public readonly array $allowedSorts;

    /**
     * @param array<int, SortSpec> $allowedSorts
     */
    public function __construct(int $defaultLimit, int $maxLimit, string $defaultSortToken, array $allowedSorts)
    {
        if ($defaultLimit < 1 || $maxLimit < 1 || $defaultLimit > $maxLimit) {
            throw new InvalidArgumentException('Invalid pagination limits.');
        }
        if ($allowedSorts === []) {
            throw new InvalidArgumentException('At least one sort must be configured.');
        }

        $sortTokens = [];
        foreach ($allowedSorts as $sortSpec) {
            if (!$sortSpec instanceof SortSpec) {
                throw new InvalidArgumentException('Invalid sort spec type.');
            }
            if (isset($sortTokens[$sortSpec->token])) {
                throw new InvalidArgumentException('Duplicate sort token.');
            }
            $sortTokens[$sortSpec->token] = true;
        }

        if (!isset($sortTokens[$defaultSortToken])) {
            throw new InvalidArgumentException('Default sort token must exist in allowed sorts.');
        }

        $this->defaultLimit = $defaultLimit;
        $this->maxLimit = $maxLimit;
        $this->defaultSortToken = $defaultSortToken;
        $this->allowedSorts = $allowedSorts;
    }

    public function getSortSpec(string $token): ?SortSpec
    {
        foreach ($this->allowedSorts as $sortSpec) {
            if ($sortSpec->token === $token) {
                return $sortSpec;
            }
        }

        return null;
    }
}
