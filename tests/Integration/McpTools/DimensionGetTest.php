<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Piwik\Plugins\CustomDimensions\API as CustomDimensionsApi;
use Piwik\Plugins\McpServer\McpTools\DimensionGet;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class DimensionGetTest extends IntegrationTestCase
{
    private int $idSite = 0;
    private int $idDimension = 0;
    private string $dimensionName = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Dimension Get Test Site',
            'https://dimension-get.test',
        );

        $suffix = substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8);
        $this->dimensionName = 'MCP Dimension Get ' . $suffix;
        $this->idDimension = (int) CustomDimensionsApi::getInstance()->configureNewCustomDimension(
            $this->idSite,
            $this->dimensionName,
            'action',
            1,
            [['dimension' => 'url', 'pattern' => 'customer=(.*)']],
            false,
        );
    }

    public function testReturnsExpectedContent(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            DimensionGet::TOOL_NAME,
            ['idSite' => $this->idSite, 'idDimension' => $this->idDimension],
            __METHOD__,
        );

        self::assertSame([
            'iddimension' => $this->idDimension,
            'idsite' => $this->idSite,
            'name' => $this->dimensionName,
            'index' => 1,
            'scope' => 'action',
            'active' => true,
            'case_sensitive' => false,
            'extractions' => [
                ['dimension' => 'url', 'pattern' => 'customer=(.*)'],
            ],
        ], $content);
    }

    public function testReturnsErrorForMissingDimension(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            DimensionGet::TOOL_NAME,
            ['idSite' => $this->idSite, 'idDimension' => 999999],
            'Dimension not found.',
            __METHOD__,
        );
    }

    public function testReturnsErrorForDimensionWithoutViewAccess(): void
    {
        McpAuthTestHelper::asNoAccessUser(function (): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            McpTestHelper::callToolAndAssertError(
                $server,
                $sessionId,
                DimensionGet::TOOL_NAME,
                ['idSite' => $this->idSite, 'idDimension' => $this->idDimension],
                'Dimension not found.',
                __METHOD__,
            );
        });
    }

    public function testReturnsInvalidParamsErrorForInvalidIdSite(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            DimensionGet::TOOL_NAME,
            ['idSite' => 0, 'idDimension' => $this->idDimension],
            __METHOD__,
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . DimensionGet::TOOL_NAME . "':",
            $message->message,
        );
        self::assertStringContainsString('idSite', $message->message);
    }

    public function testReturnsInvalidParamsErrorForInvalidIdDimension(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            DimensionGet::TOOL_NAME,
            ['idSite' => $this->idSite, 'idDimension' => 0],
            __METHOD__,
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . DimensionGet::TOOL_NAME . "':",
            $message->message,
        );
        self::assertStringContainsString('idDimension', $message->message);
    }
}
