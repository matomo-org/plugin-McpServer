<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;
use Piwik\Plugins\McpServer\Schemas\Api\ApiCallToolInputSchema;
use Piwik\Plugins\McpServer\Schemas\Api\ApiCallToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;

class ApiCallFull extends AbstractApiCall
{
    public const TOOL_NAME = 'matomo_api_call_full';

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need to execute a known Matomo API method directly and"
            . " it is not safely covered by one CRUD-specific tool.\n"
            . "Purpose: call one allowed full-access API method and return its result"
            . " plus the resolved method metadata.\n"
            . "Next: prefer CRUD-specific raw API call tools when the method"
            . " classification is known.";
        $this->annotations = new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        );
        $this->inputSchema = ApiCallToolInputSchema::SCHEMA;
        $this->outputSchema = ApiCallToolOutputSchema::ITEM;
    }

    public function shouldRegister(): bool
    {
        return $this->systemSettings->getRawApiAccessMode() === RawApiAccessMode::FULL;
    }

    protected function getExpectedOperationCategory(): ?string
    {
        return null;
    }
}
