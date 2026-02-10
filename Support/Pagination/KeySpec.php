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

final class KeySpec
{
    public const TYPE_INT = 'int';
    public const TYPE_STRING = 'string';

    public readonly string $key;
    public readonly string $type;
    public readonly string $direction;

    public function __construct(string $key, string $type, string $direction)
    {
        if ($key === '') {
            throw new InvalidArgumentException('Key must not be empty.');
        }
        if ($type !== self::TYPE_INT && $type !== self::TYPE_STRING) {
            throw new InvalidArgumentException('Unsupported key type.');
        }
        if (!SortDirection::isValid($direction)) {
            throw new InvalidArgumentException('Unsupported sort direction.');
        }

        $this->key = $key;
        $this->type = $type;
        $this->direction = $direction;
    }
}
