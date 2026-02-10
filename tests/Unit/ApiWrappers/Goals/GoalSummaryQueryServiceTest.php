<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\ApiWrappers\Goals;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Services\Goals\GoalSummaryQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class GoalSummaryQueryServiceTest extends TestCase
{
    public function testNormalizeGoalSummaryDataThrowsWhenFieldIsMissing(): void
    {
        $service = new GoalSummaryQueryService();
        $data = $this->makeValidGoalSummaryData();
        unset($data['name']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal list item is incomplete (missing 'name').");

        $service->normalizeGoalSummaryData($data, 'Goal list item');
    }

    public function testNormalizeGoalSummaryDataThrowsWhenFieldIsNull(): void
    {
        $service = new GoalSummaryQueryService();
        $data = $this->makeValidGoalSummaryData();
        $data['match_attribute'] = null;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal list item is incomplete (missing 'match_attribute').");

        $service->normalizeGoalSummaryData($data, 'Goal list item');
    }

    public function testNormalizeGoalSummaryDataReturnsExpectedTypedOutput(): void
    {
        $service = new GoalSummaryQueryService();

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
        $service = new GoalSummaryQueryService();
        $data = $this->makeValidGoalSummaryData();
        $data['revenue'] = 12.5;

        $goal = $service->normalizeGoalSummaryData($data, 'Goal list item');

        self::assertSame('12.5', $goal->revenue);
    }

    public function testNormalizeGoalSummaryRowsThrowsWhenPayloadIsNotArray(): void
    {
        $service = new GoalSummaryQueryService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Goal list data is invalid.');

        $service->normalizeGoalSummaryRows(
            'invalid',
            'Goal list data is invalid.',
            'Goal list item'
        );
    }

    public function testNormalizeGoalSummaryRowsThrowsWhenRowIsNotArray(): void
    {
        $service = new GoalSummaryQueryService();

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Goal list data is invalid.');

        $service->normalizeGoalSummaryRows(
            ['invalid'],
            'Goal list data is invalid.',
            'Goal list item'
        );
    }

    public function testNormalizeGoalSummaryRowsReturnsNormalizedRows(): void
    {
        $service = new GoalSummaryQueryService();

        $actual = $service->normalizeGoalSummaryRows(
            [$this->makeValidGoalSummaryData()],
            'Goal list data is invalid.',
            'Goal list item'
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
}
