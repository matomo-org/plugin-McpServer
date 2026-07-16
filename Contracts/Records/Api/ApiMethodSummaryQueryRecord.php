<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Records\Api;

use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;
use Piwik\Plugins\McpServer\Support\Search\SearchTermMatcher;

final class ApiMethodSummaryQueryRecord
{
    public function __construct(
        public readonly string $accessMode,
        public readonly string $module,
        public readonly string $search,
        public readonly string $operationCategory,
    ) {
    }

    public static function fromInputs(
        string $accessMode,
        ?string $module = null,
        ?string $search = null,
        ?string $operationCategory = null,
    ): self {
        return new self(
            accessMode: RawApiAccessMode::normalize($accessMode),
            module: strtolower(trim((string) $module)),
            search: SearchTermMatcher::key($search),
            operationCategory: ApiMethodOperationClassifier::normalizeCategory($operationCategory),
        );
    }
}
