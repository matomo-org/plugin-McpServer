<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Piwik\Container\Container;
use Piwik\DI;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;

return [
    PluginCapabilityGatewayInterface::class => DI::decorate(
        function (PluginCapabilityGatewayInterface $previous, Container $container): PluginCapabilityGatewayInterface {
            $mockOAuth2PluginEnabled = $container->get('test.vars.mockOAuth2PluginEnabled');

            if ($mockOAuth2PluginEnabled === null || $mockOAuth2PluginEnabled === '') {
                return $previous;
            }

            return new class ($previous, (bool) $mockOAuth2PluginEnabled) implements PluginCapabilityGatewayInterface {
                public function __construct(
                    private PluginCapabilityGatewayInterface $previous,
                    private bool $mockOAuth2PluginEnabled,
                ) {
                }

                public function isPluginActivated(string $pluginName): bool
                {
                    if ($pluginName === 'OAuth2') {
                        return $this->mockOAuth2PluginEnabled;
                    }

                    return $this->previous->isPluginActivated($pluginName);
                }
            };
        },
    ),
];
