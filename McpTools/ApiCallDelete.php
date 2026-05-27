<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\McpTools;

use Piwik\Plugins\McpServer\Contracts\McpToolAnnotations;
use Piwik\Plugins\McpServer\Schemas\Api\ApiCallToolInputSchema;
use Piwik\Plugins\McpServer\Schemas\Api\ApiCallToolOutputSchema;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\Support\Api\ApiMethodOperationClassifier;

class ApiCallDelete extends AbstractApiCall
{
    public const TOOL_NAME = 'matomo_api_call_delete';

    protected function init(): void
    {
        $this->name = self::TOOL_NAME;
        $this->description = "Use when: you need to execute a known delete-style Matomo API method directly.\n"
            . "Purpose: call one allowed delete method and return its result plus the"
            . " resolved method metadata.\n"
            . "Next: use " . ApiGet::TOOL_NAME . ' or ' . ApiList::TOOL_NAME
            . ' first if you still need to confirm the method signature.';
        $this->annotations = new McpToolAnnotations(
            readOnlyHint: false,
            destructiveHint: true,
            idempotentHint: false,
            openWorldHint: false,
        );
        $this->inputSchema = ApiCallToolInputSchema::SCHEMA;
        $this->outputSchema = ApiCallToolOutputSchema::item();
    }

    public function shouldRegister(): bool
    {
        return RawApiAccessMode::allowsCategory(
            $this->systemSettings->getRawApiAccessMode(),
            RawApiAccessMode::DELETE,
        );
    }

    /**
     * @return 'delete'
     */
    protected function getExpectedOperationCategory(): ?string
    {
        return ApiMethodOperationClassifier::CATEGORY_DELETE;
    }
}
