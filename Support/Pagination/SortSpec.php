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

final class SortSpec
{
    public readonly string $token;
    public readonly KeySpec $primary;
    /**
     * @var array<int, KeySpec>
     */
    public readonly array $tieBreakers;

    /**
     * @param array<int, KeySpec> $tieBreakers
     */
    public function __construct(string $token, KeySpec $primary, array $tieBreakers = [])
    {
        if ($token === '') {
            throw new InvalidArgumentException('Sort token must not be empty.');
        }

        $this->token = $token;
        $this->primary = $primary;
        $this->tieBreakers = $tieBreakers;
    }

    /**
     * @return array<int, KeySpec>
     */
    public function keyChain(): array
    {
        return array_merge([$this->primary], $this->tieBreakers);
    }
}
