<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Reports;

/**
 * @phpstan-type ReportSummaryArray array{
 *     uniqueId: string,
 *     module: string,
 *     action: string,
 *     name: string,
 *     category: string,
 *     parameters: array<string, mixed>,
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
            'parameters' => $this->parameters,
        ];
    }
}
