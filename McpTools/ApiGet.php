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
use Piwik\Plugins\McpServer\Contracts\McpToolAnnotations;
use Piwik\Plugins\McpServer\Contracts\Ports\Api\ApiMethodSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Records\Api\ApiMethodSummaryRecord;
use Piwik\Plugins\McpServer\Schemas\Api\ApiGetToolInputSchema;
use Piwik\Plugins\McpServer\Schemas\Api\ApiMethodSummaryToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @phpstan-import-type ApiMethodSummaryArray from ApiMethodSummaryRecord
 */
class ApiGet extends McpTool
{
    public const TOOL_NAME = 'matomo_api_get';

    public function __construct(
        private ApiMethodSummaryQueryServiceInterface $queryService,
        private SystemSettings $systemSettings,
    ) {
        parent::__construct();
    }

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you already know the Matomo API method name and need its exact signature.\n"
            . "Purpose: return one authoritative API method summary with parameter metadata.\n"
            . "Do not use: for broad discovery across APIs; use " . ApiList::TOOL_NAME . ' instead.';
        $this->annotations = new McpToolAnnotations(
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
        $this->inputSchema = ApiGetToolInputSchema::SCHEMA;
        $this->outputSchema = ApiMethodSummaryToolOutputSchema::ITEM;
    }

    public function shouldRegister(): bool
    {
        return RawApiAccessMode::allowsToolRegistration($this->systemSettings->getRawApiAccessMode());
    }

    /**
     * @return ApiMethodSummaryArray
     */
    public function execute(?string $method = null, ?string $module = null, ?string $action = null): array
    {
        return $this->queryService->getApiMethodSummaryBySelector(
            $this->systemSettings->getRawApiAccessMode(),
            $method,
            $module,
            $action,
        )->toArray();
    }
}
