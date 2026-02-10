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
use Piwik\Plugins\McpServer\Services\Goals\GoalDetailQueryService;

/**
 * @group McpServer
 * @group Plugins
 */
class GoalDetailQueryServiceTest extends TestCase
{
    public function testNormalizeGoalDetailDataThrowsWhenFieldIsMissing(): void
    {
        $service = new GoalDetailQueryService();
        $data = $this->makeValidGoalDetailData();
        unset($data['name']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal data is incomplete (missing 'name').");

        $service->normalizeGoalDetailData($data, 'Goal data');
    }

    public function testNormalizeGoalDetailDataThrowsWhenFieldIsInvalid(): void
    {
        $service = new GoalDetailQueryService();
        $data = $this->makeValidGoalDetailData();
        $data['case_sensitive'] = 'invalid';

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal data is invalid (field 'case_sensitive').");

        $service->normalizeGoalDetailData($data, 'Goal data');
    }

    public function testNormalizeGoalDetailDataReturnsExpectedTypedOutput(): void
    {
        $service = new GoalDetailQueryService();

        $goal = $service->normalizeGoalDetailData($this->makeValidGoalDetailData(), 'Goal data');

        self::assertSame([
            'idgoal' => 3,
            'idsite' => 5,
            'name' => 'Goal Name',
            'description' => 'Goal Description',
            'match_attribute' => 'event_action',
            'allow_multiple' => true,
            'revenue' => '9.99',
            'event_value_as_revenue' => false,
            'pattern' => 'evt-alpha',
            'pattern_type' => 'exact',
            'case_sensitive' => true,
        ], $goal->toArray());
    }

    public function testNormalizeGoalDetailDataCastsNumericRevenueToString(): void
    {
        $service = new GoalDetailQueryService();
        $data = $this->makeValidGoalDetailData();
        $data['revenue'] = 12.5;

        $goal = $service->normalizeGoalDetailData($data, 'Goal data');

        self::assertSame('12.5', $goal->revenue);
    }

    public function testNormalizeGoalDetailDataSetsExpandedFieldsNullForManualGoal(): void
    {
        $service = new GoalDetailQueryService();
        $data = $this->makeValidGoalDetailData();
        $data['match_attribute'] = 'manually';
        unset($data['pattern'], $data['pattern_type'], $data['case_sensitive']);

        $goal = $service->normalizeGoalDetailData($data, 'Goal data');

        self::assertNull($goal->pattern);
        self::assertNull($goal->patternType);
        self::assertNull($goal->caseSensitive);
    }

    public function testNormalizeGoalDetailDataThrowsWhenPatternMissingForNonManualGoal(): void
    {
        $service = new GoalDetailQueryService();
        $data = $this->makeValidGoalDetailData();
        unset($data['pattern']);

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage("Goal data is incomplete (missing 'pattern').");

        $service->normalizeGoalDetailData($data, 'Goal data');
    }

    /**
     * @return array<string, mixed>
     */
    private function makeValidGoalDetailData(): array
    {
        return [
            'idgoal' => '3',
            'idsite' => '5',
            'name' => 'Goal Name',
            'description' => 'Goal Description',
            'match_attribute' => 'event_action',
            'allow_multiple' => '1',
            'revenue' => '9.99',
            'event_value_as_revenue' => '0',
            'pattern' => 'evt-alpha',
            'pattern_type' => 'exact',
            'case_sensitive' => '1',
        ];
    }
}
