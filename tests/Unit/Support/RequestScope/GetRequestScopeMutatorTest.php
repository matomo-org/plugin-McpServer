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
        $_REQUEST = [];
        parent::tearDown();
    }

    public function testRunWithParametersRestoresGetOnSuccess(): void
    {
        $_GET = ['existing' => 'keep'];
        $_REQUEST = ['request_existing' => 'request_keep'];
        $mutator = new GetRequestScopeMutator();

        $result = $mutator->runWithParameters(
            ['filter_limit' => '2', 'flat' => '1'],
            static function (): string {
                self::assertSame('keep', $_GET['existing'] ?? null);
                self::assertSame('2', $_GET['filter_limit'] ?? null);
                self::assertSame('1', $_GET['flat'] ?? null);
                self::assertSame('request_keep', $_REQUEST['request_existing'] ?? null);
                self::assertSame('2', $_REQUEST['filter_limit'] ?? null);
                self::assertSame('1', $_REQUEST['flat'] ?? null);

                return 'ok';
            }
        );

        self::assertSame('ok', $result);
        self::assertSame(['existing' => 'keep'], $_GET);
        self::assertSame(['request_existing' => 'request_keep'], $_REQUEST);
    }

    public function testRunWithParametersRestoresGetOnException(): void
    {
        $_GET = ['existing' => 'keep'];
        $_REQUEST = ['request_existing' => 'request_keep'];
        $mutator = new GetRequestScopeMutator();

        try {
            $mutator->runWithParameters(
                ['filter_limit' => '2'],
                static function (): void {
                    self::assertSame('2', $_GET['filter_limit'] ?? null);
                    self::assertSame('2', $_REQUEST['filter_limit'] ?? null);
                    throw new \RuntimeException('boom');
                }
            );
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame(['existing' => 'keep'], $_GET);
        self::assertSame(['request_existing' => 'request_keep'], $_REQUEST);
    }

    public function testRunWithParametersOverridesAndThenRestoresExistingKeys(): void
    {
        $_GET = ['filter_limit' => '50', 'existing' => 'keep'];
        $_REQUEST = ['filter_limit' => '70', 'request_existing' => 'request_keep'];
        $mutator = new GetRequestScopeMutator();

        $mutator->runWithParameters(
            ['filter_limit' => '2'],
            static function (): void {
                self::assertSame('2', $_GET['filter_limit'] ?? null);
                self::assertSame('keep', $_GET['existing'] ?? null);
                self::assertSame('2', $_REQUEST['filter_limit'] ?? null);
                self::assertSame('request_keep', $_REQUEST['request_existing'] ?? null);
            }
        );

        self::assertSame(['filter_limit' => '50', 'existing' => 'keep'], $_GET);
        self::assertSame(['filter_limit' => '70', 'request_existing' => 'request_keep'], $_REQUEST);
    }

    public function testRunWithParametersSupportsNestedScopesAndRestoresOuterState(): void
    {
        $_GET = ['outer' => '1'];
        $_REQUEST = ['outer' => 'request_1'];
        $mutator = new GetRequestScopeMutator();

        $mutator->runWithParameters(
            ['inner' => 'outer_scope'],
            static function () use ($mutator): void {
                self::assertSame('outer_scope', $_GET['inner'] ?? null);
                self::assertSame('outer_scope', $_REQUEST['inner'] ?? null);

                $mutator->runWithParameters(
                    ['inner' => 'inner_scope', 'another' => 'value'],
                    static function (): void {
                        self::assertSame('inner_scope', $_GET['inner'] ?? null);
                        self::assertSame('value', $_GET['another'] ?? null);
                        self::assertSame('inner_scope', $_REQUEST['inner'] ?? null);
                        self::assertSame('value', $_REQUEST['another'] ?? null);
                    }
                );

                self::assertSame('outer_scope', $_GET['inner'] ?? null);
                self::assertArrayNotHasKey('another', $_GET);
                self::assertSame('outer_scope', $_REQUEST['inner'] ?? null);
                self::assertArrayNotHasKey('another', $_REQUEST);
            }
        );

        self::assertSame(['outer' => '1'], $_GET);
        self::assertSame(['outer' => 'request_1'], $_REQUEST);
    }

    public function testRunWithParametersSupportsNestedExceptionUnwinding(): void
    {
        $_GET = ['outer' => '1'];
        $_REQUEST = ['outer' => 'request_1'];
        $mutator = new GetRequestScopeMutator();

        try {
            $mutator->runWithParameters(
                ['inner' => 'outer_scope'],
                static function () use ($mutator): void {
                    $mutator->runWithParameters(
                        ['inner' => 'inner_scope'],
                        static function (): void {
                            throw new \RuntimeException('nested boom');
                        }
                    );
                }
            );
            self::fail('Expected RuntimeException was not thrown.');
        } catch (\RuntimeException $e) {
            self::assertSame('nested boom', $e->getMessage());
        }

        self::assertSame(['outer' => '1'], $_GET);
        self::assertSame(['outer' => 'request_1'], $_REQUEST);
    }
}
