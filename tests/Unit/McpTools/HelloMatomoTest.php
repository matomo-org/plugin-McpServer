<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\McpTools;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\McpTools\HelloMatomo;

/**
 * @group McpServer
 * @group Plugins
 */
class HelloMatomoTest extends TestCase
{
    public function testHelloReturnsMatomoGreeting(): void
    {
        $tool = new HelloMatomo();

        $this->assertSame(['hello' => 'Matomo'], $tool->hello());
    }
}
