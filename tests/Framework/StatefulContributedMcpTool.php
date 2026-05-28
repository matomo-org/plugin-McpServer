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
 * Test tool whose execute() result depends on constructor state.
 */
final class StatefulContributedMcpTool extends McpTool
{
    public const TOOL_NAME = 'matomo_test_stateful_contributed_tool';
    public const FALLBACK_STATE = 'fallback-state-from-reconstructed-tool';

    public function __construct(private string $state = self::FALLBACK_STATE)
    {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = 'Test-only MCP tool that exposes constructor state.';
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
                    'description' => 'Value echoed by the test tool.',
                ],
            ],
            'additionalProperties' => false,
        ];
        $this->outputSchema = [
            'type' => 'object',
            'properties' => [
                'state' => ['type' => 'string'],
                'value' => ['type' => ['string', 'null']],
            ],
            'required' => ['state', 'value'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array{state: string, value: string|null}
     */
    public function execute(?string $value = null): array
    {
        return [
            'state' => $this->state,
            'value' => $value,
        ];
    }
}
