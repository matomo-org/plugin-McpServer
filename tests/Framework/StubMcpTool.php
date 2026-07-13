<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Framework;

use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\McpToolAnnotations;

/**
 * Minimal but fully renderable {@see McpTool} used by tests that need a tool
 * the real McpServerFactory can register and list. The tool name is supplied
 * by the caller so tests can assert on it; execute() is never exercised by the
 * listTools path.
 */
final class StubMcpTool extends McpTool
{
    public function __construct(private string $toolName)
    {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = $this->toolName;
        $this->description = 'Test-only MCP tool contributed through the McpServer tool events.';
        $this->annotations = new McpToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
        $this->inputSchema = [
            'type' => 'object',
            'properties' => [
                'value' => [
                    'type' => 'string',
                    'description' => 'Ignored test parameter.',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(?string $value = null): array
    {
        return ['value' => $value];
    }
}
