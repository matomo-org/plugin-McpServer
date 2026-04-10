<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Sites;

use Piwik\Exception\UnexpectedWebsiteFoundException;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\CoreSitesManagerGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Sites\SiteDetailRecord;
use Piwik\Plugins\McpServer\Support\Errors\ToolErrorMapper;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class SiteDetailQueryService implements SiteDetailQueryServiceInterface
{
    public function __construct(
        private CoreSitesManagerGatewayInterface $coreSitesManagerGateway,
    ) {
    }

    public function getSiteDetailFromId(int $idSite): SiteDetailRecord
    {
        try {
            $site = $this->coreSitesManagerGateway->getSiteFromId($idSite);
        } catch (\Throwable $e) {
            ToolErrorMapper::throwDetailFailure(
                $e,
                'Site not found or access denied.',
                'Site retrieval failed.',
                fn(\Throwable $error): bool => $this->isNotFoundOrNoAccessLikeFailure($error)
            );
        }

        $siteData = ToolDataNormalizer::requireStringKeyedArray($site, 'Site retrieval failed.');

        return $this->normalizeSiteDetailData($siteData);
    }

    /**
     * Public for testability and to share normalization contract across MCP tools.
     *
     * @param array<string, mixed> $site
     */
    public function normalizeSiteDetailData(array $site): SiteDetailRecord
    {
        $context = 'Site data';

        return new SiteDetailRecord(
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

    private function isNotFoundOrNoAccessLikeFailure(\Throwable $e): bool
    {
        if ($e instanceof UnexpectedWebsiteFoundException) {
            return true;
        }

        $message = strtolower(trim((string) $e->getMessage()));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'no access')
            || str_contains($message, 'checkuserhasviewaccess')
            || str_contains($message, 'view access')
            || str_contains($message, 'does not exist')
            || str_contains($message, 'website')
            || str_contains($message, 'not found');
    }
}
