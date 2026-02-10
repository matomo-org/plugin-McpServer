<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Pagination;

final class PageRequest
{
    public readonly ?int $limit;
    public readonly ?string $sortToken;
    public readonly ?string $cursor;
    public readonly ?string $cursorContext;

    public function __construct(
        ?int $limit = null,
        ?string $sortToken = null,
        ?string $cursor = null,
        ?string $cursorContext = null
    )
    {
        $this->limit = $limit;
        $this->sortToken = $sortToken;
        $this->cursor = $cursor;
        $this->cursorContext = $cursorContext;
    }
}
