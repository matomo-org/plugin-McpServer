<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Records\Api;

final class ApiMethodSummaryQueryRecord
{
    public function __construct(
        public readonly string $accessMode,
        public readonly string $module,
        public readonly string $search,
    ) {
    }

    public static function fromInputs(string $accessMode, ?string $module = null, ?string $search = null): self
    {
        return new self(
            accessMode: trim($accessMode),
            module: strtolower(trim((string) $module)),
            search: strtolower(trim((string) $search)),
        );
    }
}
