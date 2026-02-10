<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Framework;

use Matomo\Dependencies\McpServer\Mcp\Server;
use PHPUnit\Framework\Assert;

final class PaginationMutationScenarioHelper
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public static function callToolAndGetStructuredContent(
        Server $server,
        string $sessionId,
        string $toolName,
        array $arguments,
        string $requestId
    ): array {
        $payload = McpTestHelper::makeCallToolRequest($toolName, $arguments, $requestId);
        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseCallTool($message);

        Assert::assertFalse($result->isError);
        Assert::assertIsArray($result->structuredContent);

        return $result->structuredContent;
    }
}
