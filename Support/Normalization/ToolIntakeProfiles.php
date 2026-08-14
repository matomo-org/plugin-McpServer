<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Normalization;

use Piwik\Plugins\McpServer\McpTools\ApiCallCreate;
use Piwik\Plugins\McpServer\McpTools\ApiCallDelete;
use Piwik\Plugins\McpServer\McpTools\ApiCallFull;
use Piwik\Plugins\McpServer\McpTools\ApiCallRead;
use Piwik\Plugins\McpServer\McpTools\ApiCallUpdate;
use Piwik\Plugins\McpServer\McpTools\ApiGet;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;

/**
 * The registry of tools that accept alternate input representations, and what each one accepts.
 *
 * This is the single allowlist. A tool absent from here is not normalized, and a representation
 * absent from a listed profile stays invalid, so widening recovery is a visible edit to this file.
 *
 * The raw API-call tools share one profile because they share `ApiCallToolInputSchema` and
 * `AbstractApiCall`: the same logical call has to canonicalise identically whichever of them
 * carries it.
 *
 * Tools used in sequence share a selector convergence, because a model reads a selector from the
 * discovery tool and passes it to the executing one: the two report tools share
 * `ReportSelectorConvergence`, and `matomo_api_get` shares `ApiSelectorConvergence` with the five
 * call tools. Each pair declares the same selector keys and resolves them through the same lookup;
 * only the surrounding parameter handling differs, hence sharing the convergence rather than the
 * whole profile.
 *
 * The registry is closed - a `match` over built-in tool classes, with no extension event - so a
 * tool contributed through `McpServer.addTools` is never rewritten. Keying on the class rather
 * than the tool name keeps a listener that displaces a built-in name from being normalized against
 * a schema it does not publish, and the match is exact, since a subclass may narrow that schema.
 */
final class ToolIntakeProfiles
{
    private function __construct()
    {
    }

    /**
     * @param class-string|null $toolClass the class registered under the called tool name, or
     *                                     null when the name resolves to no known class
     */
    public static function forToolClass(?string $toolClass): ?ToolIntakeProfile
    {
        return match ($toolClass) {
            ReportProcessed::class => self::processedReportProfile(),
            ReportMetadata::class => self::reportMetadataProfile(),
            ApiCallRead::class,
            ApiCallCreate::class,
            ApiCallUpdate::class,
            ApiCallDelete::class,
            ApiCallFull::class => self::apiCallProfile(),
            ApiGet::class => self::apiGetProfile(),
            default => null,
        };
    }

    private static function processedReportProfile(): ToolIntakeProfile
    {
        // `apiParameters` is the parameter container, since the relocations and lifts below move
        // values in and out of it, and an object field, so a JSON-string form is decoded and an
        // empty array is accepted at validation.
        return new ToolIntakeProfile(
            keyAliases: [
                'filterLimit' => 'filter_limit',
                'filterOffset' => 'filter_offset',
            ],
            objectFields: ['apiParameters'],
            parameterContainer: 'apiParameters',
            // Generic parameters that shape the report rather than select it. All four pass
            // ReportProcessedQueryService's generic-safe key check inside `apiParameters`, so
            // relocating changes where the value is written, not whether it is allowed.
            relocations: [
                'expanded' => 'expanded',
                'flat' => 'flat',
                'filter_sort_column' => 'filter_sort_column',
                'filter_sort_order' => 'filter_sort_order',
            ],
            // `segment` is not generic-safe, so a nested one lands in the report-specific bucket
            // and fails the call. A lifted value passes the same validation as a top-level one.
            //
            // A nested `filter_limit`/`filter_offset` is not lifted: `ReportProcessedQueryService`
            // overwrites both keys from the top-level arguments, so such a request is answered
            // with the default page size rather than rejected. This profile recovers requests the
            // schema rejects; lifting these would newly reject an out-of-range or non-integer
            // nested value that is tolerated today.
            lifts: [
                'segment' => 'segment',
            ],
            selectorConvergence: new ReportSelectorConvergence(),
        );
    }

    private static function reportMetadataProfile(): ToolIntakeProfile
    {
        // No parameterContainer, since nothing is registered to move: these `apiParameters` carry
        // the report-specific selector of a parameterized report rather than generic report
        // controls, and the schema forbids them beside a `reportUniqueId`. `apiParameters` stays an
        // object field, so a JSON-string form is decoded and an empty array is accepted at
        // validation.
        return new ToolIntakeProfile(
            objectFields: ['apiParameters'],
            selectorConvergence: new ReportSelectorConvergence(),
        );
    }

    private static function apiCallProfile(): ToolIntakeProfile
    {
        // No parameterContainer: these tools register no relocation or lift, and a container
        // without either has no effect. `parameters` is an object field, so a JSON-string form is
        // decoded and an empty array is accepted at validation.
        return new ToolIntakeProfile(
            objectFields: ['parameters'],
            selectorConvergence: new ApiSelectorConvergence(),
        );
    }

    private static function apiGetProfile(): ToolIntakeProfile
    {
        // The convergence only, not the call tools' whole profile: this tool publishes no
        // parameter object, so there is nothing to decode and no empty list to accept, and this
        // registry exists to keep unpublished fields unregistered.
        return new ToolIntakeProfile(
            selectorConvergence: new ApiSelectorConvergence(),
        );
    }
}
