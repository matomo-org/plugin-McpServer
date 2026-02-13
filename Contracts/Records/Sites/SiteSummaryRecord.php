<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Records\Sites;

/**
 * @phpstan-type SiteSummaryArray array{
 *     idsite: int,
 *     name: string,
 *     main_url: string,
 *     type: string,
 * }
 */
final class SiteSummaryRecord
{
    public function __construct(
        public readonly int $idSite,
        public readonly string $name,
        public readonly string $mainUrl,
        public readonly string $type
    ) {
    }

    /**
     * @return SiteSummaryArray
     */
    public function toArray(): array
    {
        return [
            'idsite' => $this->idSite,
            'name' => $this->name,
            'main_url' => $this->mainUrl,
            'type' => $this->type,
        ];
    }
}
