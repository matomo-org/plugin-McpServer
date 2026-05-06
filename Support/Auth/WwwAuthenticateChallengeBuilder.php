<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Support\Auth;

class WwwAuthenticateChallengeBuilder
{
    private const REALM = 'mcp';

    public function __construct(private ProtectedResourceMetadataProvider $protectedResourceMetadataProvider)
    {
    }

    public function build(): string
    {
        $challenge = sprintf('Bearer realm="%s"', self::REALM);

        $metadataUrl = $this->protectedResourceMetadataProvider->getMetadataUrl();
        if ($metadataUrl === '') {
            return $challenge;
        }

        if (!$this->protectedResourceMetadataProvider->isAvailable()) {
            return $challenge;
        }

        return sprintf('%s, resource_metadata="%s"', $challenge, $metadataUrl);
    }
}
