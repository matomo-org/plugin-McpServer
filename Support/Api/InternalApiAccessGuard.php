<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Api;

use Piwik\API\Request as ApiRequest;
use Piwik\NoAccessException;

/**
 * Gate the McpServer internal API methods so they are only reachable when the
 * HTTP entry was not itself an API request. Canonical callers are Matomo
 * controllers (or other non-API entry points) that invoke the internal methods
 * through Request::processRequest(), which keeps the standard API event hooks
 * and monitoring intact while still leaving the root request non-API.
 *
 * Direct HTTP API entries — including bulk and processed-report dispatchers
 * that forward caller-supplied method names — are rejected wholesale, so the
 * guard does not need a denylist of specific dispatcher methods. Rejection
 * uses NoAccessException so the API renderer surfaces it as the authorisation
 * error it semantically is, rather than a generic 400 bad-request.
 */
final class InternalApiAccessGuard
{
    public function assertInternalContext(): void
    {
        if (ApiRequest::isRootRequestApiRequest()) {
            throw new NoAccessException(
                'McpServer internal API methods are only available to in-process callers '
                . 'invoked from a Matomo controller (or other non-API entry point).',
            );
        }
    }
}
