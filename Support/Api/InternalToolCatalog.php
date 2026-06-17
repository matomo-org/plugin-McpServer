<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

use Matomo\Dependencies\McpServer\Mcp\Schema\Tool;
use Piwik\Plugins\McpServer\Server\InternalAccess;

/**
 * Translate the SDK-shaped registry of MCP tools into a flat, framework-only
 * array shape suitable for cross-plugin consumption via the Matomo API layer.
 *
 * No SDK types leak across the boundary - callers see plain arrays. The
 * inputSchema and outputSchema arrays may themselves contain `stdClass`
 * placeholders where a tool used `new \stdClass()` to force JSON `{}` for
 * an "any value" schema fragment; preserving those is intentional so that
 * a consumer re-encoding the catalogue to JSON for an external MCP client
 * keeps the spec-valid `{}` instead of accidentally emitting `[]`.
 *
 * Behavioural hints are passed through as `?bool` rather than coerced to a
 * default: `null` means the tool did not declare that hint, and consumers
 * decide how to interpret "unknown" (the MCP spec itself leaves that policy
 * to clients — e.g. treating readOnly=false as implicitly destructive unless
 * explicitly stated).
 */
final class InternalToolCatalog
{
    /**
     * @return list<array{
     *     name: string,
     *     title: string|null,
     *     description: string,
     *     inputSchema: array<string, mixed>,
     *     outputSchema: array<string, mixed>|null,
     *     readOnly: bool|null,
     *     destructive: bool|null,
     *     idempotent: bool|null,
     *     openWorld: bool|null
     * }>
     */
    public function build(InternalAccess $access): array
    {
        $entries = [];
        foreach ($access->registry->getTools()->references as $reference) {
            if (!$reference instanceof Tool) {
                continue;
            }

            $annotations = $reference->annotations;
            $entries[] = [
                'name' => $reference->name,
                'title' => $reference->title,
                'description' => $reference->description ?? '',
                'inputSchema' => $reference->inputSchema,
                'outputSchema' => $reference->outputSchema,
                'readOnly' => $annotations?->readOnlyHint,
                'destructive' => $annotations?->destructiveHint,
                'idempotent' => $annotations?->idempotentHint,
                'openWorld' => $annotations?->openWorldHint,
            ];
        }

        return $entries;
    }
}
