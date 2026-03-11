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
 * @phpstan-import-type ApiMethodSummaryArray from ApiMethodSummaryRecord
 * @phpstan-type ApiCallArray array{
 *     result: mixed,
 *     resolvedMethod: ApiMethodSummaryArray,
 * }
 */
final class ApiCallRecord
{
    public function __construct(
        public readonly mixed $result,
        public readonly ApiMethodSummaryRecord $resolvedMethod,
    ) {
    }

    /**
     * @return ApiCallArray
     */
    public function toArray(): array
    {
        return [
            'result' => $this->result,
            'resolvedMethod' => $this->resolvedMethod->toArray(),
        ];
    }
}
