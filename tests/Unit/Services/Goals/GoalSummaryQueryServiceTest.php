<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Goals;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\McpToolCallException;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\CoreGoalsGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\Services\Goals\GoalSummaryQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class GoalSummaryQueryServiceTest extends TestCase
{
    public function testGetGoalSummariesForSiteThrowsWhenPluginIsUnavailable(): void
    {
        $gateway = $this->createMock(CoreGoalsGatewayInterface::class);
        $gateway->expects(self::never())->method('getGoals');

        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->expects(self::once())
            ->method('isPluginActivated')
            ->with('Goals')
            ->willReturn(false);

        $service = new GoalSummaryQueryService($gateway, $capabilityGateway);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Goals plugin is not available.');
        $service->getGoalSummariesForSite(5);
    }

    public function testNormalizeGoalSummaryDataThrowsWhenFieldIsMissing(): void
    {
        $service = $this->createService();
        $data = $this->makeValidGoalSummaryData();
        unset($data['name']);

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Goal list item is incomplete (missing 'name').");

        $service->normalizeGoalSummaryData($data, 'Goal list item');
    }

    public function testNormalizeGoalSummaryDataThrowsWhenFieldIsNull(): void
    {
        $service = $this->createService();
        $data = $this->makeValidGoalSummaryData();
        $data['match_attribute'] = null;

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage("Goal list item is incomplete (missing 'match_attribute').");

        $service->normalizeGoalSummaryData($data, 'Goal list item');
    }

    public function testNormalizeGoalSummaryDataReturnsExpectedTypedOutput(): void
    {
        $service = $this->createService();

        $goal = $service->normalizeGoalSummaryData($this->makeValidGoalSummaryData(), 'Goal list item');

        self::assertSame([
            'idgoal' => 3,
            'idsite' => 5,
            'name' => 'Goal Name',
            'description' => '',
            'match_attribute' => 'event_action',
            'allow_multiple' => true,
            'revenue' => '9.99',
            'event_value_as_revenue' => false,
        ], $goal->toArray());
    }

    public function testNormalizeGoalSummaryDataCastsNumericRevenueToString(): void
    {
        $service = $this->createService();
        $data = $this->makeValidGoalSummaryData();
        $data['revenue'] = 12.5;

        $goal = $service->normalizeGoalSummaryData($data, 'Goal list item');

        self::assertSame('12.5', $goal->revenue);
    }

    public function testNormalizeGoalSummaryRowsThrowsWhenPayloadIsNotArray(): void
    {
        $service = $this->createService();

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Goal list data is invalid.');

        $service->normalizeGoalSummaryRows(
            'invalid',
            'Goal list data is invalid.',
            'Goal list item',
        );
    }

    public function testNormalizeGoalSummaryRowsThrowsWhenRowIsNotArray(): void
    {
        $service = $this->createService();

        $this->expectException(McpToolCallException::class);
        $this->expectExceptionMessage('Goal list data is invalid.');

        $service->normalizeGoalSummaryRows(
            ['invalid'],
            'Goal list data is invalid.',
            'Goal list item',
        );
    }

    public function testNormalizeGoalSummaryRowsReturnsNormalizedRows(): void
    {
        $service = $this->createService();

        $actual = $service->normalizeGoalSummaryRows(
            [$this->makeValidGoalSummaryData()],
            'Goal list data is invalid.',
            'Goal list item',
        );

        self::assertCount(1, $actual);
        self::assertSame([
            'idgoal' => 3,
            'idsite' => 5,
            'name' => 'Goal Name',
            'description' => '',
            'match_attribute' => 'event_action',
            'allow_multiple' => true,
            'revenue' => '9.99',
            'event_value_as_revenue' => false,
        ], $actual[0]->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidGoalSummaryData(): array
    {
        return [
            'idgoal' => '3',
            'idsite' => '5',
            'name' => 'Goal Name',
            'description' => '',
            'match_attribute' => 'event_action',
            'allow_multiple' => '1',
            'revenue' => '9.99',
            'event_value_as_revenue' => '0',
        ];
    }

    private function createService(): GoalSummaryQueryService
    {
        $capabilityGateway = $this->createMock(PluginCapabilityGatewayInterface::class);
        $capabilityGateway->method('isPluginActivated')->willReturn(true);

        return new GoalSummaryQueryService(
            $this->createMock(CoreGoalsGatewayInterface::class),
            $capabilityGateway,
        );
    }
}
