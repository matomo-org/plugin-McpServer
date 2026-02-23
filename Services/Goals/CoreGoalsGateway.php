<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Goals;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\API\Request;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\CoreGoalsGatewayInterface;
use Piwik\Plugins\McpServer\Support\Normalization\ToolDataNormalizer;

final class CoreGoalsGateway implements CoreGoalsGatewayInterface
{
    /** @var callable|null */
    private $requestProcessor;

    public function __construct(?callable $requestProcessor = null)
    {
        $this->requestProcessor = $requestProcessor;
    }

    public function getGoals(int $idSite): array
    {
        $goals = $this->processRequest('Goals.getGoals', [
            'idSite' => (string) $idSite,
            'orderByName' => true,
        ]);

        return $this->normalizeRows($goals, 'Goals data is invalid.');
    }

    public function getGoal(int $idSite, int $idGoal): array
    {
        $goal = $this->processRequest('Goals.getGoal', [
            'idSite' => $idSite,
            'idGoal' => $idGoal,
        ]);

        return ToolDataNormalizer::requireStringKeyedArray($goal, 'Goal data is invalid.');
    }

    /**
     * @param array<string, mixed> $paramOverride
     */
    private function processRequest(string $method, array $paramOverride): mixed
    {
        if ($this->requestProcessor !== null) {
            return ($this->requestProcessor)($method, $paramOverride, []);
        }

        return Request::processRequest($method, $paramOverride, []);
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
