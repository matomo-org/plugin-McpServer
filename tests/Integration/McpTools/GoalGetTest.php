<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Piwik\Plugins\Goals\API as GoalsApi;
use Piwik\Plugins\McpServer\McpTools\GoalGet;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class GoalGetTest extends IntegrationTestCase
{
    private int $idSite = 0;
    private int $idGoal = 0;
    private string $goalName = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Goal Get Test Site',
            'https://goal-get.test',
        );

        $suffix = substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8);
        $this->goalName = 'MCP Goal Get ' . $suffix;
        $this->idGoal = (int) GoalsApi::getInstance()->addGoal(
            $this->idSite,
            $this->goalName,
            'event_action',
            'evt-goal-get',
            'exact',
            false,
            false,
            true,
            'MCP Goal Get Description',
            true,
        );
    }

    public function testReturnsExpectedContent(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $content = McpTestHelper::callToolAndAssertSuccess(
            $server,
            $sessionId,
            GoalGet::TOOL_NAME,
            ['idSite' => $this->idSite, 'idGoal' => $this->idGoal],
            __METHOD__,
        );

        self::assertSame([
            'idgoal' => $this->idGoal,
            'idsite' => $this->idSite,
            'name' => $this->goalName,
            'description' => 'MCP Goal Get Description',
            'match_attribute' => 'event_action',
            'allow_multiple' => true,
            'revenue' => '0',
            'event_value_as_revenue' => true,
            'pattern' => 'evt-goal-get',
            'pattern_type' => 'exact',
            'case_sensitive' => false,
        ], $content);
    }

    public function testReturnsErrorForMissingGoal(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        McpTestHelper::callToolAndAssertError(
            $server,
            $sessionId,
            GoalGet::TOOL_NAME,
            ['idSite' => $this->idSite, 'idGoal' => 999999],
            'Goal not found.',
            __METHOD__,
        );
    }

    public function testReturnsErrorForGoalWithoutViewAccess(): void
    {
        McpAuthTestHelper::asNoAccessUser(function (): void {
            $server = McpTestHelper::buildServer();
            $sessionId = McpTestHelper::initializeSession($server);
            McpTestHelper::callToolAndAssertError(
                $server,
                $sessionId,
                GoalGet::TOOL_NAME,
                ['idSite' => $this->idSite, 'idGoal' => $this->idGoal],
                'Goal not found.',
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
            GoalGet::TOOL_NAME,
            ['idSite' => 0, 'idGoal' => $this->idGoal],
            __METHOD__,
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . GoalGet::TOOL_NAME . "':",
            $message->message,
        );
        self::assertStringContainsString('idSite', $message->message);
    }

    public function testReturnsInvalidParamsErrorForInvalidIdGoal(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $message = McpTestHelper::callToolExpectInvalidParams(
            $server,
            $sessionId,
            GoalGet::TOOL_NAME,
            ['idSite' => $this->idSite, 'idGoal' => 0],
            __METHOD__,
        );
        self::assertStringContainsString(
            "Invalid parameters for tool '" . GoalGet::TOOL_NAME . "':",
            $message->message,
        );
        self::assertStringContainsString('idGoal', $message->message);
    }
}
