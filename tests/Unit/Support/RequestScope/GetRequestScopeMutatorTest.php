<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\RequestScope;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\RequestScope\GetRequestScopeMutator;

/**
 * @group McpServer
 * @group Plugins
 */
class GetRequestScopeMutatorTest extends TestCase
{
    protected function tearDown(): void
    {
        $_GET = [];
        parent::tearDown();
    }

    public function testRunWithParametersRestoresGetOnSuccess(): void
    {
        $_GET = ['existing' => 'keep'];
        $mutator = new GetRequestScopeMutator();

        $result = $mutator->runWithParameters(
            ['filter_limit' => '2', 'flat' => '1'],
            static function (): string {
                self::assertSame('keep', $_GET['existing'] ?? null);
                self::assertSame('2', $_GET['filter_limit'] ?? null);
                self::assertSame('1', $_GET['flat'] ?? null);

                return 'ok';
            }
        );

        self::assertSame('ok', $result);
        self::assertSame(['existing' => 'keep'], $_GET);
    }

    public function testRunWithParametersRestoresGetOnException(): void
    {
        $_GET = ['existing' => 'keep'];
        $mutator = new GetRequestScopeMutator();

        try {
            $mutator->runWithParameters(
                ['filter_limit' => '2'],
                static function (): void {
                    self::assertSame('2', $_GET['filter_limit'] ?? null);
                    throw new \RuntimeException('boom');
                }
            );
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(['existing' => 'keep'], $_GET);
    }

    public function testRunWithParametersOverridesAndThenRestoresExistingKeys(): void
    {
        $_GET = ['filter_limit' => '50', 'existing' => 'keep'];
        $mutator = new GetRequestScopeMutator();

        $mutator->runWithParameters(
            ['filter_limit' => '2'],
            static function (): void {
                self::assertSame('2', $_GET['filter_limit'] ?? null);
                self::assertSame('keep', $_GET['existing'] ?? null);
            }
        );

        self::assertSame(['filter_limit' => '50', 'existing' => 'keep'], $_GET);
    }
}
