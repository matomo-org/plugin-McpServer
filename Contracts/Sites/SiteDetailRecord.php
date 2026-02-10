<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Sites;

/**
 * @phpstan-type SiteDetailArray array{
 *     idsite: int,
 *     name: string,
 *     main_url: string,
 *     timezone: string,
 *     timezone_name: string,
 *     currency: string,
 *     currency_name: string,
 *     ecommerce: bool,
 *     sitesearch: bool,
 *     type: string,
 * }
 */
final class SiteDetailRecord
{
    public function __construct(
        public readonly int $idSite,
        public readonly string $name,
        public readonly string $mainUrl,
        public readonly string $timezone,
        public readonly string $timezoneName,
        public readonly string $currency,
        public readonly string $currencyName,
        public readonly bool $ecommerce,
        public readonly bool $siteSearch,
        public readonly string $type
    ) {
    }

    /**
     * @return SiteDetailArray
     */
    public function toArray(): array
    {
        return [
            'idsite' => $this->idSite,
            'name' => $this->name,
            'main_url' => $this->mainUrl,
            'timezone' => $this->timezone,
            'timezone_name' => $this->timezoneName,
            'currency' => $this->currency,
            'currency_name' => $this->currencyName,
            'ecommerce' => $this->ecommerce,
            'sitesearch' => $this->siteSearch,
            'type' => $this->type,
        ];
    }
}
