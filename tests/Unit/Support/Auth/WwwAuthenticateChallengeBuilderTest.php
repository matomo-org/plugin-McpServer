<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Auth;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Auth\ProtectedResourceMetadataProvider;
use Piwik\Plugins\McpServer\Support\Auth\WwwAuthenticateChallengeBuilder;
use Piwik\Plugins\McpServer\SystemSettings;

/**
 * @group McpServer
 * @group Plugins
 */
class WwwAuthenticateChallengeBuilderTest extends TestCase
{
    public function testReturnsBaseChallengeWhenProtectedResourceMetadataIsUnavailable(): void
    {
        $builder = new WwwAuthenticateChallengeBuilder(
            $this->createProvider(
                false,
                'https://matomo.example.test/index.php?module=McpServer&action=oauthProtectedResourceMetadata',
            ),
        );

        self::assertSame('Bearer realm="mcp"', $builder->build());
    }

    public function testReturnsBaseChallengeWhenMetadataUrlIsEmpty(): void
    {
        $builder = new WwwAuthenticateChallengeBuilder($this->createProvider(true, ''));

        self::assertSame('Bearer realm="mcp"', $builder->build());
    }

    public function testIncludesProtectedResourceMetadataUrlWhenAvailable(): void
    {
        $builder = new WwwAuthenticateChallengeBuilder(
            $this->createProvider(
                true,
                'https://matomo.example.test/index.php?module=McpServer&action=oauthProtectedResourceMetadata',
            ),
        );

        self::assertSame(
            'Bearer realm="mcp", resource_metadata="'
                . 'https://matomo.example.test/index.php?module=McpServer&action=oauthProtectedResourceMetadata"',
            $builder->build(),
        );
    }

    private function createProvider(bool $available, string $metadataUrl): ProtectedResourceMetadataProvider
    {
        return new class (
            $this->createMock(SystemSettings::class),
            $available,
            $metadataUrl,
        ) extends ProtectedResourceMetadataProvider {
            public function __construct(
                SystemSettings $settings,
                private bool $available,
                private string $metadataUrl,
            ) {
                parent::__construct($settings);
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getMetadataUrl(): string
            {
                return $this->metadataUrl;
            }
        };
    }
}
