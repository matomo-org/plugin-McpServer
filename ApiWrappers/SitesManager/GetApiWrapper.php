<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\ApiWrappers\SitesManager;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;
use Piwik\Plugins\SitesManager\API as SitesManagerApi;

final class GetApiWrapper implements GetApiWrapperInterface
{
    public function getSiteRecordFromId(int $idSite): SiteRecord
    {
        try {
            $site = SitesManagerApi::getInstance()->getSiteFromId($idSite);
        } catch (NoAccessException | UnexpectedWebsiteFoundException $e) {
            // Intentional: collapse not-found and no-access to avoid information disclosure.
            throw new ToolCallException('Site not found or access denied.');
        }

        return $this->normalizeSiteData($site);
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $site
     */
    public function normalizeSiteData(array $site): SiteRecord
    {
        $context = 'Site data';

        return new SiteRecord(
            idSite: ToolDataNormalizer::requireIntLikeField($site, 'idsite', $context),
            name: ToolDataNormalizer::requireStringField($site, 'name', $context),
            mainUrl: ToolDataNormalizer::requireStringField($site, 'main_url', $context),
            timezone: ToolDataNormalizer::requireStringField($site, 'timezone', $context),
            timezoneName: ToolDataNormalizer::requireStringField($site, 'timezone_name', $context),
            currency: ToolDataNormalizer::requireStringField($site, 'currency', $context),
            currencyName: ToolDataNormalizer::requireStringField($site, 'currency_name', $context),
            ecommerce: ToolDataNormalizer::requireBoolLikeField($site, 'ecommerce', $context),
            siteSearch: ToolDataNormalizer::requireBoolLikeField($site, 'sitesearch', $context),
            type: ToolDataNormalizer::requireStringField($site, 'type', $context),
        );
    }
}
