<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Sites;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Services\Sites\CoreSitesManagerGateway;

/**
 * @group McpServer
 * @group Plugins
 */
class CoreSitesManagerGatewayTest extends TestCase
{
    public function testGetSitesWithMinimumAccessReturnsTypedList(): void
    {
        $gateway = new CoreSitesManagerGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                self::assertSame('SitesManager.getSitesWithMinimumAccess', $method);
                self::assertSame(['permission' => 'view', 'pattern' => 'site', 'limit' => 2], $paramOverride);
                self::assertSame([], $defaultRequest);

                return [
                    ['idsite' => '1', 'name' => 'Site Alpha'],
                    ['idsite' => '2', 'name' => 'Site Beta'],
                ];
            },
        );
        $result = $gateway->getSitesWithMinimumAccess('view', 'site', 2);

        self::assertCount(2, $result);
        self::assertSame('Site Alpha', $result[0]['name'] ?? null);
        self::assertSame('Site Beta', $result[1]['name'] ?? null);
    }

    public function testGetSitesWithMinimumAccessRejectsInvalidTopLevelPayload(): void
    {
        $gateway = new CoreSitesManagerGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return ['unexpected' => 'shape'];
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site list data is invalid.');
        $gateway->getSitesWithMinimumAccess('view', '', null);
    }

    public function testGetSitesWithMinimumAccessRejectsInvalidRowPayload(): void
    {
        $gateway = new CoreSitesManagerGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return [
                    ['idsite' => '1', 'name' => 'Site Alpha'],
                    ['invalid-row'],
                ];
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site list data is invalid.');
        $gateway->getSitesWithMinimumAccess('view', '', null);
    }

    public function testGetSiteFromIdReturnsTypedRow(): void
    {
        $gateway = new CoreSitesManagerGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                self::assertSame('SitesManager.getSiteFromId', $method);
                self::assertSame(['idSite' => 4], $paramOverride);
                self::assertSame([], $defaultRequest);

                return ['idsite' => '4', 'name' => 'Site Detail'];
            },
        );
        $result = $gateway->getSiteFromId(4);

        self::assertSame('Site Detail', $result['name'] ?? null);
    }

    public function testGetSiteFromIdRejectsInvalidRowPayload(): void
    {
        $gateway = new CoreSitesManagerGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return ['invalid-row'];
            },
        );

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Site data is invalid.');
        $gateway->getSiteFromId(4);
    }
}
