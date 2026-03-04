<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer;

use Piwik\Piwik;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Setting;
use Piwik\SettingsPiwik;

class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    /** @var Setting */
    public $enableMcp;

    protected function init(): void
    {
        $this->enableMcp = $this->makeSetting(
            'enable_mcp',
            false,
            FieldConfig::TYPE_BOOL,
            function (FieldConfig $field) {
                $field->title = Piwik::translate('McpServer_EnableMcpTitle');
                $field->inlineHelp = implode('<br><br>', [
                    Piwik::translate('McpServer_EnableMcpHelpPurpose'),
                    Piwik::translate('McpServer_EnableMcpHelpDataScope'),
                    Piwik::translate('McpServer_EnableMcpHelpPolicy'),
                    Piwik::translate('McpServer_EnableMcpHelpUrl', [$this->getMcpEndpointUrl()]),
                ]);
                $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            }
        );
    }

    public function isMcpEnabled(): bool
    {
        return (bool) $this->enableMcp->getValue();
    }

    private function getMcpEndpointUrl(): string
    {
        $baseUrl = (string) SettingsPiwik::getPiwikUrl();

        return rtrim($baseUrl, '/') . '/index.php?module=API&method=McpServer.mcp&format=mcp';
    }
}
