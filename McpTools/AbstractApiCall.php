<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiCallQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiCallRecord;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @phpstan-import-type ApiCallArray from ApiCallRecord
 */
abstract class AbstractApiCall
{
    private const UNAVAILABLE_MESSAGE = 'API method not found or unavailable.';

    public function __construct(
        private ApiCallQueryServiceInterface $queryService,
        private ApiMethodSummaryQueryServiceInterface $apiMethodSummaryQueryService,
        private SystemSettings $systemSettings,
    ) {
    }

    /**
     * @param array<string, mixed>|null $parameters
     * @return ApiCallArray
     */
    public function call(
        ?string $method = null,
        ?string $module = null,
        ?string $action = null,
        ?array $parameters = null,
    ): array {
        $accessMode = $this->systemSettings->getRawApiAccessMode();
        $resolvedMethod = $this->apiMethodSummaryQueryService->getApiMethodSummaryBySelector(
            $accessMode,
            $method,
            $module,
            $action,
        );

        $expectedOperationCategory = $this->getExpectedOperationCategory();
        if (
            $expectedOperationCategory !== null
            && $resolvedMethod->operationCategory !== $expectedOperationCategory
        ) {
            throw new ToolCallException(self::UNAVAILABLE_MESSAGE);
        }

        return $this->queryService->callApi(
            $resolvedMethod,
            $parameters,
        )->toArray();
    }

    /**
     * @return ?non-empty-string
     */
    abstract protected function getExpectedOperationCategory(): ?string;
}
