<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiCallQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiCallRecord;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @phpstan-import-type ApiCallArray from ApiCallRecord
 */
abstract class AbstractApiCall extends McpTool
{
    private const UNAVAILABLE_MESSAGE = 'API method not found or unavailable.';

    public function __construct(
        private ApiCallQueryServiceInterface $queryService,
        private ApiMethodSummaryQueryServiceInterface $apiMethodSummaryQueryService,
        protected SystemSettings $systemSettings,
    ) {
        parent::__construct();
    }

    /**
     * @param array<string, mixed>|null $parameters
     * @return ApiCallArray
     */
    public function execute(
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
            $this->fail(self::UNAVAILABLE_MESSAGE);
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
