<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Segments;

use Piwik\API\Request;
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\CoreSegmentEditorGatewayInterface;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\NoAccessLikeErrorDetector;
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
            throw new McpToolCallException($invalidDataMessage);
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
