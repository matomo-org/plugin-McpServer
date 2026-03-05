<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Segments;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\API\Request;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\CoreSegmentEditorGatewayInterface;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class CoreSegmentEditorGateway implements CoreSegmentEditorGatewayInterface
{
    /** @var callable|null */
    private $requestProcessor;

    public function __construct(?callable $requestProcessor = null)
    {
        $this->requestProcessor = $requestProcessor;
    }

    public function getAll(int $idSite): array
    {
        $segments = $this->processRequest('SegmentEditor.getAll', [
            'idSite' => $idSite,
        ]);

        return $this->normalizeRows($segments, 'Segment data is invalid.');
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
            if ($this->isNoAccessLikeFailure($e)) {
                throw new AccessDeniedLikeException('No access to this resource.', 0, $e);
            }

            throw $e;
        }
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
