<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Records\Reports;

/**
 * @phpstan-type ReportSummaryArray array{
 *     uniqueId: string,
 *     module: string,
 *     action: string,
 *     name: string,
 *     category: string,
 *     parameters: array<string, mixed>|\stdClass,
 * }
 */
final class ReportSummaryRecord
{
    /**
     * @param array<string, mixed> $parameters
     */
    public function __construct(
        public readonly string $uniqueId,
        public readonly string $module,
        public readonly string $action,
        public readonly string $name,
        public readonly string $category,
        public readonly array $parameters
    ) {
    }

    /**
     * @return ReportSummaryArray
     */
    public function toArray(): array
    {
        return [
            'uniqueId' => $this->uniqueId,
            'module' => $this->module,
            'action' => $this->action,
            'name' => $this->name,
            'category' => $this->category,
            // Preserve JSON object encoding for empty parameter maps on the MCP transport boundary.
            'parameters' => $this->parameters === [] ? new \stdClass() : $this->parameters,
        ];
    }
}
