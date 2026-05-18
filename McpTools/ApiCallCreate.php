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
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;

class ApiCallCreate extends AbstractApiCall
{
    public const TOOL_NAME = 'matomo_api_call_create';

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need to execute a known create-style Matomo API method directly.\n"
            . "Purpose: call one allowed create method and return its result plus the"
            . " resolved method metadata.\n"
            . "Next: use " . ApiGet::TOOL_NAME . ' or ' . ApiList::TOOL_NAME
            . ' first if you still need to confirm the method signature.';
        $this->annotations = new ToolAnnotations(
            readOnlyHint: false,
            destructiveHint: false,
            idempotentHint: false,
            openWorldHint: false,
        );
        $this->inputSchema = ApiCallToolInputSchema::SCHEMA;
        $this->outputSchema = ApiCallToolOutputSchema::ITEM;
    }

    public function shouldRegister(): bool
    {
        return RawApiAccessMode::allowsCategory(
            $this->systemSettings->getRawApiAccessMode(),
            RawApiAccessMode::CREATE,
        );
    }

    /**
     * @return 'create'
     */
    protected function getExpectedOperationCategory(): ?string
    {
        return ApiMethodOperationClassifier::CATEGORY_CREATE;
    }
}
