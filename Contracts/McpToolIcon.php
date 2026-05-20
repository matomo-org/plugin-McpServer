<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts;

/**
 * Optional icon advertised alongside an McpTool in tool listings. The src is
 * the only required field (a URL or data URI); mimeType narrows how the
 * client should decode it and sizes lists the available display dimensions
 * (e.g. "32x32").
 */
final class McpToolIcon
{
    /**
     * @param list<string>|null $sizes
     */
    public function __construct(
        public readonly string $src,
        public readonly ?string $mimeType = null,
        public readonly ?array $sizes = null,
    ) {
    }
}
