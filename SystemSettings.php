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
use Piwik\Plugins\McpServer\Support\Access\McpAccessLevel;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Settings\FieldConfig;
use Piwik\Settings\Setting;
use Piwik\SettingsPiwik;

class SystemSettings extends \Piwik\Settings\Plugin\SystemSettings
{
    private const RAW_API_ACCESS_SCOPE_NONE = 'none';
    private const RAW_API_ACCESS_SCOPE_PARTIAL = 'partial';
    private const RAW_API_ACCESS_SCOPE_FULL = 'full';

    /** @var Setting */
    public $enableMcp;

    /** @var Setting */
    public $maximumMcpAccessLevel;

    /** @var Setting */
    public $rawApiAccessScope;

    /** @var Setting */
    public $rawApiAccessRead;

    /** @var Setting */
    public $rawApiAccessCreate;

    /** @var Setting */
    public $rawApiAccessUpdate;

    /** @var Setting */
    public $rawApiAccessDelete;

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

        $this->maximumMcpAccessLevel = $this->makeSetting(
            'maximum_mcp_access_level',
            McpAccessLevel::UNLIMITED,
            FieldConfig::TYPE_STRING,
            function (FieldConfig $field) {
                $field->title = Piwik::translate('McpServer_MaximumMcpAccessLevelTitle');
                $field->inlineHelp = implode('<br><br>', [
                    Piwik::translate('McpServer_MaximumMcpAccessLevelHelpPurpose'),
                    Piwik::translate('McpServer_MaximumMcpAccessLevelHelpTokens'),
                    Piwik::translate('McpServer_MaximumMcpAccessLevelHelpSeparateUser'),
                ]);
                $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
                $field->condition = 'enable_mcp==1';
                $field->availableValues = [
                    McpAccessLevel::UNLIMITED => Piwik::translate('McpServer_MaximumMcpAccessLevelUnlimited'),
                    McpAccessLevel::VIEW => Piwik::translate('McpServer_MaximumMcpAccessLevelView'),
                    McpAccessLevel::WRITE => Piwik::translate('McpServer_MaximumMcpAccessLevelWrite'),
                    McpAccessLevel::ADMIN => Piwik::translate('McpServer_MaximumMcpAccessLevelAdmin'),
                ];
            },
        );

        $sharedRawApiInlineHelp = implode('<br><br>', [
            Piwik::translate('McpServer_RawApiAccessHelpPurpose'),
            Piwik::translate('McpServer_RawApiAccessHelpReadFallback'),
            Piwik::translate('McpServer_RawApiAccessHelpDataScope'),
            Piwik::translate('McpServer_RawApiAccessHelpDestructive'),
            Piwik::translate('McpServer_RawApiAccessHelpPolicy'),
        ]);

        $this->rawApiAccessScope = $this->makeSetting(
            'raw_api_access_scope',
            self::RAW_API_ACCESS_SCOPE_NONE,
            FieldConfig::TYPE_STRING,
            function (FieldConfig $field) use ($sharedRawApiInlineHelp) {
                $field->title = Piwik::translate('McpServer_RawApiAccessScopeTitle');
                $field->inlineHelp = $sharedRawApiInlineHelp;
                $field->uiControl = FieldConfig::UI_CONTROL_SINGLE_SELECT;
                $field->condition = 'enable_mcp==1';
                $field->availableValues = [
                    self::RAW_API_ACCESS_SCOPE_NONE => Piwik::translate('McpServer_RawApiAccessScopeNone'),
                    self::RAW_API_ACCESS_SCOPE_PARTIAL => Piwik::translate('McpServer_RawApiAccessScopePartial'),
                    self::RAW_API_ACCESS_SCOPE_FULL => Piwik::translate('McpServer_RawApiAccessScopeFull'),
                ];
            },
        );

        $this->rawApiAccessRead = $this->makeSetting(
            'raw_api_access_read',
            false,
            FieldConfig::TYPE_BOOL,
            function (FieldConfig $field) {
                $field->title = Piwik::translate('McpServer_RawApiAccessReadTitle');
                $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
                $field->condition = 'enable_mcp==1 && raw_api_access_scope=="partial"';
            },
        );

        $this->rawApiAccessCreate = $this->makeSetting(
            'raw_api_access_create',
            false,
            FieldConfig::TYPE_BOOL,
            function (FieldConfig $field) {
                $field->title = Piwik::translate('McpServer_RawApiAccessCreateTitle');
                $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
                $field->condition = 'enable_mcp==1 && raw_api_access_scope=="partial"';
            },
        );

        $this->rawApiAccessUpdate = $this->makeSetting(
            'raw_api_access_update',
            false,
            FieldConfig::TYPE_BOOL,
            function (FieldConfig $field) {
                $field->title = Piwik::translate('McpServer_RawApiAccessUpdateTitle');
                $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
                $field->condition = 'enable_mcp==1 && raw_api_access_scope=="partial"';
            },
        );

        $this->rawApiAccessDelete = $this->makeSetting(
            'raw_api_access_delete',
            false,
            FieldConfig::TYPE_BOOL,
            function (FieldConfig $field) {
                $field->title = Piwik::translate('McpServer_RawApiAccessDeleteTitle');
                $field->uiControl = FieldConfig::UI_CONTROL_CHECKBOX;
                $field->condition = 'enable_mcp==1 && raw_api_access_scope=="partial"';
            },
        );
    }

    public function isMcpEnabled(): bool
    {
        return (bool) $this->enableMcp->getValue();
    }

    public function getMaximumAllowedMcpAccessLevel(): string
    {
        return McpAccessLevel::normalizeMaximumAllowed($this->maximumMcpAccessLevel->getValue());
    }

    public function getRawApiAccessMode(): string
    {
        $scope = $this->normalizeRawApiAccessScope($this->rawApiAccessScope->getValue());
        if ($scope === self::RAW_API_ACCESS_SCOPE_FULL) {
            return RawApiAccessMode::FULL;
        }

        if ($scope !== self::RAW_API_ACCESS_SCOPE_PARTIAL) {
            return RawApiAccessMode::NONE;
        }

        return RawApiAccessMode::fromBooleans(
            (bool) $this->rawApiAccessRead->getValue(),
            (bool) $this->rawApiAccessCreate->getValue(),
            (bool) $this->rawApiAccessUpdate->getValue(),
            (bool) $this->rawApiAccessDelete->getValue(),
            false,
        );
    }

    private function normalizeRawApiAccessScope(mixed $scope): string
    {
        if (!is_scalar($scope)) {
            return self::RAW_API_ACCESS_SCOPE_NONE;
        }

        $normalizedScope = strtolower(trim((string) $scope));
        if (
            $normalizedScope !== self::RAW_API_ACCESS_SCOPE_NONE
            && $normalizedScope !== self::RAW_API_ACCESS_SCOPE_PARTIAL
            && $normalizedScope !== self::RAW_API_ACCESS_SCOPE_FULL
        ) {
            return self::RAW_API_ACCESS_SCOPE_NONE;
        }

        return $normalizedScope;
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
