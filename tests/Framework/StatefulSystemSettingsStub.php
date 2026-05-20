<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Framework;

use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * Hand-rolled SystemSettings stub used by the McpServer unit suite. Skips the
 * parent constructor so that the Matomo plugin-settings infrastructure does not
 * need to be bootstrapped, and exposes a public state-tracked raw API access
 * mode. Tests obtain instances via {@see McpTestHelper::installSystemSettingsStub()},
 * which installs a fresh stub into the current StaticContainer and returns the
 * handle for subsequent mode changes.
 */
class StatefulSystemSettingsStub extends SystemSettings
{
    public string $currentRawApiAccessMode = RawApiAccessMode::NONE;

    public function __construct()
    {
        // Intentionally skip parent::__construct(): only getRawApiAccessMode()
        // is exercised on this stub.
    }

    public function getRawApiAccessMode(): string
    {
        return $this->currentRawApiAccessMode;
    }
}
