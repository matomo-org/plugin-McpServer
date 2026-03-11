<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Services\Api\CoreApiCallGateway;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;

/**
 * @group McpServer
 * @group Plugins
 */
class CoreApiCallGatewayTest extends TestCase
{
    public function testCallDelegatesToRequestProcessor(): void
    {
        $gateway = new CoreApiCallGateway(
            static function (string $method, array $parameters, array $extra): array {
                TestCase::assertSame('API.getMatomoVersion', $method);
                TestCase::assertSame(['idSite' => 3], $parameters);
                TestCase::assertSame([], $extra);

                return ['version' => '6.0.0'];
            },
        );

        self::assertSame(['version' => '6.0.0'], $gateway->call('API.getMatomoVersion', ['idSite' => 3]));
    }

    public function testCallMapsNoAccessException(): void
    {
        $gateway = new CoreApiCallGateway(
            static function (string $method, array $parameters, array $extra): mixed {
                throw new NoAccessException('denied');
            },
        );

        $this->expectException(AccessDeniedLikeException::class);
        $this->expectExceptionMessage('No access to API method.');

        $gateway->call('API.getMatomoVersion', []);
    }

    public function testCallMapsUnexpectedFailures(): void
    {
        $gateway = new CoreApiCallGateway(
            static function (string $method, array $parameters, array $extra): mixed {
                throw new \RuntimeException('timeout');
            },
        );

        $this->expectException(CoreApiRequestException::class);
        $this->expectExceptionMessage('Matomo API request failed.');

        $gateway->call('API.getMatomoVersion', []);
    }
}
