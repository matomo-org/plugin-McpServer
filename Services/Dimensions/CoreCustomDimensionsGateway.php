<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Dimensions;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\API\Request;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\CoreCustomDimensionsGatewayInterface;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class CoreCustomDimensionsGateway implements CoreCustomDimensionsGatewayInterface
{
    public function getConfiguredCustomDimensions(int $idSite): array
    {
        try {
            $dimensions = Request::processRequest('CustomDimensions.getConfiguredCustomDimensions', [
                'idSite' => $idSite,
            ], []);
        } catch (\Throwable $e) {
            if ($this->isNoAccessLikeFailure($e)) {
                throw new AccessDeniedLikeException('No access to this resource.', 0, $e);
            }

            throw $e;
        }

        return $this->normalizeRows($dimensions, 'Custom dimensions data is invalid.');
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

    private function isNoAccessLikeFailure(\Throwable $e): bool
    {
        if ($e instanceof AccessDeniedLikeException || $e instanceof \Piwik\NoAccessException) {
            return true;
        }

        $message = strtolower(trim((string) $e->getMessage()));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'no access')
            || str_contains($message, 'checkuserhasviewaccess')
            || str_contains($message, 'view access');
    }
}
