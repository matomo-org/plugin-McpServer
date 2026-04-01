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
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Setting;
use Piwik\SettingsPiwik;

class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    /** @var Setting */
    public $enableMcp;

    /** @var Setting */
    public $rawApiAccessMode;

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
                    Piwik::translate('McpServer_EnableMcpHelpUrl', ['<code>', $this->getMcpEndpointUrl(), '</code>']),
                    Piwik::translate(
                        'McpServer_EnableMcpHelpConnectGuide',
                        ['<a href="' . $this->getConnectGuideUrl() . '">', '</a>'],
                    ),
                ]);
                $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
            },
        );

        $this->rawApiAccessMode = $this->makeSetting(
            'raw_api_access_mode',
            RawApiAccessMode::NONE,
            FieldConfig::TYPE_STRING,
            function (FieldConfig $field) {
                $field->title = Piwik::translate('McpServer_RawApiAccessModeTitle');
                $field->inlineHelp = implode('<br><br>', [
                    Piwik::translate('McpServer_RawApiAccessModeHelpPurpose'),
                    Piwik::translate('McpServer_RawApiAccessModeHelpReadFallback'),
                    Piwik::translate('McpServer_RawApiAccessModeHelpDataScope'),
                    Piwik::translate('McpServer_RawApiAccessModeHelpDestructive'),
                    Piwik::translate('McpServer_RawApiAccessModeHelpPolicy'),
                ]);
                $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
                $field->condition = 'enable_mcp==1';
                $field->availableValues = [
                    RawApiAccessMode::NONE => Piwik::translate('McpServer_RawApiAccessModeOptionNone'),
                    RawApiAccessMode::READ => Piwik::translate('McpServer_RawApiAccessModeOptionRead'),
                    RawApiAccessMode::CREATE => Piwik::translate('McpServer_RawApiAccessModeOptionCreate'),
                    RawApiAccessMode::UPDATE => Piwik::translate('McpServer_RawApiAccessModeOptionUpdate'),
                    RawApiAccessMode::DELETE => Piwik::translate('McpServer_RawApiAccessModeOptionDelete'),
                    RawApiAccessMode::FULL => Piwik::translate('McpServer_RawApiAccessModeOptionFull'),
                ];
            },
        );
    }

    public function isMcpEnabled(): bool
    {
        return (bool) $this->enableMcp->getValue();
    }

    public function getRawApiAccessMode(): string
    {
        return RawApiAccessMode::normalize($this->rawApiAccessMode->getValue());
    }

    private function getMcpEndpointUrl(): string
    {
        return $this->getNormalizedBaseUrl() . '/index.php?module=API&method=McpServer.mcp&format=mcp';
    }

    private function getConnectGuideUrl(): string
    {
        return $this->getNormalizedBaseUrl() . '/index.php?module=McpServer&action=connect';
    }

    private function getNormalizedBaseUrl(): string
    {
        return rtrim((string) SettingsPiwik::getPiwikUrl(), '/');
    }

    public function isOAuth2PluginEnabled(): bool
    {
        return Manager::getInstance()->isPluginActivated('OAuth2');
    }
}
