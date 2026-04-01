<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Access;

use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;

final class RawApiMethodPolicy
{
    /** @var array<string, true> */
    private const DENIED_METHODS = [
        'api.get' => true,
        'api.getbulkrequest' => true,
        'api.getmetadata' => true,
        'api.getprocessedreport' => true,
        'api.getreportmetadata' => true,
        'api.getrowevolution' => true,
        'imagegraph.get' => true,
        'insights.getinsights' => true,
        'insights.getmoversandshakers' => true,
        'treemapvisualization.gettreemapdata' => true,
    ];

    public static function allowsMethod(
        string $accessMode,
        string $method,
        string $action,
        ?string $operationCategory = null,
        ?string $classificationConfidence = null,
    ): bool {
        if (self::isDeniedMethod($method)) {
            return false;
        }

        if ($accessMode === RawApiAccessMode::FULL) {
            return true;
        }

        if ($accessMode === RawApiAccessMode::NONE) {
            return false;
        }

        if (self::normalizeConfidence($classificationConfidence) === ApiMethodOperationClassifier::CONFIDENCE_LOW) {
            return false;
        }

        return RawApiAccessMode::allowsCategory($accessMode, $operationCategory);
    }

    public static function isDeniedMethod(string $method): bool
    {
        return isset(self::DENIED_METHODS[self::normalizeSelectorValue($method)]);
    }

    private static function normalizeConfidence(?string $confidence): string
    {
        $normalizedConfidence = self::normalizeSelectorValue($confidence);

        if (
            $normalizedConfidence !== ApiMethodOperationClassifier::CONFIDENCE_HIGH
            && $normalizedConfidence !== ApiMethodOperationClassifier::CONFIDENCE_MEDIUM
        ) {
            return ApiMethodOperationClassifier::CONFIDENCE_LOW;
        }

        return $normalizedConfidence;
    }

    private static function normalizeSelectorValue(?string $value): string
    {
        return strtolower(trim((string) $value));
    }
}
