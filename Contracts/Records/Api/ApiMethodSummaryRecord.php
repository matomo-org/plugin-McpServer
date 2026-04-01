<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Records\Api;

/**
 * @phpstan-type ApiMethodParameterArray array{
 *     name: string,
 *     type: string|null,
 *     required: bool,
 *     allowsNull: bool,
 *     hasDefault: bool,
 *     defaultValue: mixed,
 * }
 * @phpstan-type ApiMethodSummaryArray array{
 *     module: string,
 *     action: string,
 *     method: string,
 *     parameters: list<ApiMethodParameterArray>,
 *     operationCategory: string|null,
 * }
 */
final class ApiMethodSummaryRecord
{
    /** @param list<ApiMethodParameterArray> $parameters */
    public function __construct(
        public readonly string $module,
        public readonly string $action,
        public readonly string $method,
        public readonly array $parameters,
        public readonly ?string $operationCategory = null,
        // Internal-only metadata for access-policy decisions and debugging.
        // Not intended to be exposed through public MCP tool responses.
        public readonly string $classificationConfidence = 'low',
        public readonly string $classificationReason = 'not-classified',
    ) {
    }

    /**
     * @return ApiMethodSummaryArray
     */
    public function toArray(): array
    {
        return [
            'module' => $this->module,
            'action' => $this->action,
            'method' => $this->method,
            'parameters' => $this->parameters,
            'operationCategory' => $this->operationCategory,
        ];
    }
}
