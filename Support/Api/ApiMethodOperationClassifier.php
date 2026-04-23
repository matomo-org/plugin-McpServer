<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

final class ApiMethodOperationClassifier
{
    public const CATEGORY_READ = 'read';
    public const CATEGORY_CREATE = 'create';
    public const CATEGORY_UPDATE = 'update';
    public const CATEGORY_DELETE = 'delete';
    public const CATEGORY_UNCATEGORIZED = 'uncategorized';

    public const CONFIDENCE_HIGH = 'high';
    public const CONFIDENCE_MEDIUM = 'medium';
    public const CONFIDENCE_LOW = 'low';

    /** @var array<string, true> */
    private const HIGH_CONFIDENCE_PREFIXES = [
        'get' => true,
        'is' => true,
        'add' => true,
        'create' => true,
        'update' => true,
        'delete' => true,
        'remove' => true,
    ];

    /** @var array<string, string> */
    private const MEDIUM_CONFIDENCE_PREFIX_TO_CATEGORY = [
        'has' => self::CATEGORY_READ,
        'can' => self::CATEGORY_READ,
        'was' => self::CATEGORY_READ,
        'are' => self::CATEGORY_READ,
        'does' => self::CATEGORY_READ,
        'uses' => self::CATEGORY_READ,
        'check' => self::CATEGORY_READ,
        'search' => self::CATEGORY_READ,
        'find' => self::CATEGORY_READ,
        'detect' => self::CATEGORY_READ,
        'test' => self::CATEGORY_READ,
        'validate' => self::CATEGORY_READ,
        'invite' => self::CATEGORY_CREATE,
        'initiate' => self::CATEGORY_CREATE,
        'duplicate' => self::CATEGORY_CREATE,
        'set' => self::CATEGORY_UPDATE,
        'save' => self::CATEGORY_UPDATE,
        'start' => self::CATEGORY_UPDATE,
        'finish' => self::CATEGORY_UPDATE,
        'pause' => self::CATEGORY_UPDATE,
        'resume' => self::CATEGORY_UPDATE,
        'end' => self::CATEGORY_UPDATE,
        'enable' => self::CATEGORY_UPDATE,
        'disable' => self::CATEGORY_UPDATE,
        'change' => self::CATEGORY_UPDATE,
        'configure' => self::CATEGORY_UPDATE,
        'copy' => self::CATEGORY_UPDATE,
        'merge' => self::CATEGORY_UPDATE,
        'unmerge' => self::CATEGORY_UPDATE,
        'rotate' => self::CATEGORY_UPDATE,
        'star' => self::CATEGORY_UPDATE,
        'unstar' => self::CATEGORY_UPDATE,
        'archive' => self::CATEGORY_UPDATE,
        'mark' => self::CATEGORY_UPDATE,
        'clear' => self::CATEGORY_UPDATE,
        'regenerate' => self::CATEGORY_UPDATE,
        'edit' => self::CATEGORY_UPDATE,
    ];

    public static function normalizeCategory(?string $category): string
    {
        $normalized = strtolower(trim((string) $category));

        if (
            $normalized !== self::CATEGORY_READ
            && $normalized !== self::CATEGORY_CREATE
            && $normalized !== self::CATEGORY_UPDATE
            && $normalized !== self::CATEGORY_DELETE
            && $normalized !== self::CATEGORY_UNCATEGORIZED
        ) {
            return '';
        }

        return $normalized;
    }

    /**
     * @return array{
     *     operationCategory: string|null,
     *     classificationConfidence: string,
     *     classificationReason: string,
     * }
     */
    public static function classify(string $method, string $action): array
    {
        $prefix = self::extractActionPrefix($action);
        if ($prefix === '') {
            return self::unclassified('missing-action-prefix');
        }

        if (isset(self::HIGH_CONFIDENCE_PREFIXES[$prefix])) {
            return [
                'operationCategory' => self::mapHighConfidencePrefixToCategory($prefix),
                'classificationConfidence' => self::CONFIDENCE_HIGH,
                'classificationReason' => 'action-prefix:' . $prefix,
            ];
        }

        if (isset(self::MEDIUM_CONFIDENCE_PREFIX_TO_CATEGORY[$prefix])) {
            return [
                'operationCategory' => self::MEDIUM_CONFIDENCE_PREFIX_TO_CATEGORY[$prefix],
                'classificationConfidence' => self::CONFIDENCE_MEDIUM,
                'classificationReason' => 'action-prefix:' . $prefix,
            ];
        }

        if (self::hasReadExistsSuffix($action)) {
            return [
                'operationCategory' => self::CATEGORY_READ,
                'classificationConfidence' => self::CONFIDENCE_MEDIUM,
                'classificationReason' => 'action-suffix:exists',
            ];
        }

        return self::unclassified('unsupported-action-prefix:' . $prefix);
    }

    private static function extractActionPrefix(string $action): string
    {
        $normalizedAction = trim($action);
        if (preg_match('/^([a-z]+)/', $normalizedAction, $matches) !== 1) {
            return '';
        }

        return $matches[1];
    }

    private static function mapHighConfidencePrefixToCategory(string $prefix): string
    {
        return match ($prefix) {
            'get', 'is' => self::CATEGORY_READ,
            'add', 'create' => self::CATEGORY_CREATE,
            'update' => self::CATEGORY_UPDATE,
            'delete', 'remove' => self::CATEGORY_DELETE,
            default => throw new \InvalidArgumentException('Unsupported high-confidence prefix.'),
        };
    }

    private static function hasReadExistsSuffix(string $action): bool
    {
        return str_ends_with(trim($action), 'Exists');
    }

    /**
     * @return array{
     *     operationCategory: null,
     *     classificationConfidence: string,
     *     classificationReason: string,
     * }
     */
    private static function unclassified(string $reason): array
    {
        return [
            'operationCategory' => null,
            'classificationConfidence' => self::CONFIDENCE_LOW,
            'classificationReason' => $reason,
        ];
    }
}
