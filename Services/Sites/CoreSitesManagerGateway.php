<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Sites;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\API\Request;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\CoreSitesManagerGatewayInterface;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\NoAccessLikeErrorDetector;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class CoreSitesManagerGateway implements CoreSitesManagerGatewayInterface
{
    /** @var callable|null */
    private $requestProcessor;

    public function __construct(?callable $requestProcessor = null)
    {
        $this->requestProcessor = $requestProcessor;
    }

    public function getSitesWithMinimumAccess(string $minimumAccess, string $search, ?int $limit): array
    {
        $sites = $this->processRequest('SitesManager.getSitesWithMinimumAccess', [
            'permission' => $minimumAccess,
            'pattern' => $search,
            'limit' => $limit,
        ]);

        return $this->normalizeRows($sites, 'Site list data is invalid.');
    }

    public function getSiteFromId(int $idSite): array
    {
        $site = $this->processRequest('SitesManager.getSiteFromId', [
            'idSite' => $idSite,
        ]);

        return ToolDataNormalizer::requireStringKeyedArray($site, 'Site data is invalid.');
    }

    /**
     * @param array<string, mixed> $paramOverride
     */
    private function processRequest(string $method, array $paramOverride): mixed
    {
        try {
            if ($this->requestProcessor !== null) {
                return ($this->requestProcessor)($method, $paramOverride, []);
            }

            return Request::processRequest($method, $paramOverride, []);
        } catch (\Throwable $e) {
            if (NoAccessLikeErrorDetector::isDetected($e)) {
                throw new AccessDeniedLikeException('No access to this resource.', 0, $e);
            }

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeRows(mixed $rows, string $invalidDataMessage): array
    {
        if (!is_array($rows)) {
            throw new ToolCallException($invalidDataMessage);
        }

        if (!array_is_list($rows)) {
            $rows = array_values($rows);
        }

        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = ToolDataNormalizer::requireStringKeyedArray($row, $invalidDataMessage);
        }

        return $normalized;
    }
}
