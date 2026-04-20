<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Piwik\Container\Container;
use Piwik\DI;
use Piwik\Plugins\McpServer\SystemSettings;

return [
    SystemSettings::class => DI::decorate(
        function (SystemSettings $previous, Container $container): SystemSettings {
            $mockOAuth2PluginEnabled = $container->get('test.vars.mockOAuth2PluginEnabled');

            if ($mockOAuth2PluginEnabled === null || $mockOAuth2PluginEnabled === '') {
                return $previous;
            }

            return new class ((bool) $mockOAuth2PluginEnabled) extends SystemSettings {
                /** @var string */
                protected $pluginName = 'McpServer';

                public function __construct(
                    private bool $mockOAuth2PluginEnabled,
                ) {
                    parent::__construct();
                }

                public function isOAuth2PluginEnabled(): bool
                {
                    return $this->mockOAuth2PluginEnabled;
                }
            };
        },
    ),
];
