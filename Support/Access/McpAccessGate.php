<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Access;

use Piwik\Http\BadRequestException;
use Piwik\Piwik;
use Piwik\Plugins\McpServer\Support\Api\McpEndpointSpec;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * Access checks shared by the HTTP mcp() endpoint and the cross-plugin
 * internal API methods. Two named modes:
 *
 * - assertBase(): user must be authenticated with some view access AND MCP
 *   must be enabled. Used by the in-process API methods, which are gated
 *   separately by InternalApiAccessGuard against direct HTTP entry.
 *
 * - assertHttp(): base + an external-token privilege ceiling that admins
 *   configure via SystemSettings::maximumMcpAccessLevel. Intentionally not
 *   applied to internal callers: in-process consumers run inside Matomo
 *   with the user's existing session, so the "tighter ceiling for tokens"
 *   policy would only lock a super-admin out of their own in-app features
 *   for no security gain.
 *
 * Throws McpUnavailableException for "MCP disabled", BadRequestException for
 * "privilege exceeded" (HTTP-only ceiling), and NoAccessException (via Piwik
 * facade) for anonymous / no-view callers. The HTTP mcp() path catches the
 * first two and re-shapes them into JSON-RPC 403 error responses; internal
 * callers let McpUnavailableException propagate so consumers can branch on
 * the unavailable case without sniffing message strings.
 */
class McpAccessGate
{
    public function __construct(private SystemSettings $systemSettings)
    {
    }

    public function assertBase(): void
    {
        Piwik::checkUserIsNotAnonymous();
        Piwik::checkUserHasSomeViewAccess();

        if (!$this->systemSettings->isMcpEnabled()) {
            throw new McpUnavailableException(McpEndpointSpec::DISABLED_ERROR);
        }
    }

    public function assertHttp(): void
    {
        $this->assertBase();

        $maximumLevel = $this->systemSettings->getMaximumAllowedMcpAccessLevel();
        $currentLevel = McpAccessLevel::resolveCurrentUserLevel();
        if (McpAccessLevel::exceedsMaximumAllowed($currentLevel, $maximumLevel)) {
            throw new BadRequestException(McpAccessLevel::createTooHighPrivilegeMessage($maximumLevel));
        }
    }
}
