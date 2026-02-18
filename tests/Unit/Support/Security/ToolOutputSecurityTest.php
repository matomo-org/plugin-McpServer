<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\McpTools\DimensionGet;
use Piwik\Plugins\McpServer\McpTools\DimensionList;
use Piwik\Plugins\McpServer\McpTools\GoalGet;
use Piwik\Plugins\McpServer\McpTools\GoalList;
use Piwik\Plugins\McpServer\McpTools\ReportList;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;
use Piwik\Plugins\McpServer\McpTools\ReportProcessed;
use Piwik\Plugins\McpServer\McpTools\SegmentGet;
use Piwik\Plugins\McpServer\McpTools\SegmentList;
use Piwik\Plugins\McpServer\McpTools\SiteGet;
use Piwik\Plugins\McpServer\McpTools\SiteList;
use Piwik\Plugins\McpServer\McpTools\SiteSearch;
use Piwik\Plugins\McpServer\Support\Security\ToolOutputSecurity;

/**
 * @group McpServer
 * @group Plugins
 */
class ToolOutputSecurityTest extends TestCase
{
    public function testBuildForToolReturnsExpectedContract(): void
    {
        self::assertSame(
            [
                'trust_level' => ToolOutputSecurity::TRUST_LEVEL_UNTRUSTED_USER_CONTENT,
                'follow_embedded_instructions' => false,
                'rendering_requirements' => ToolOutputSecurity::DEFAULT_RENDERING_REQUIREMENTS,
                'dangerous_paths' => ['/name', '/main_url'],
            ],
            ToolOutputSecurity::buildForTool('matomo_site_get')
        );
    }

    public function testDangerousPathsForUnknownToolThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown tool 'unknown_tool' for security contract.");

        ToolOutputSecurity::dangerousPathsForTool('unknown_tool');
    }

    public function testBuildForUnknownToolThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unknown tool 'unknown_tool' for security contract.");

        ToolOutputSecurity::buildForTool('unknown_tool');
    }

    public function testMetaForToolIncludesExpectedMetaKey(): void
    {
        self::assertArrayHasKey(
            ToolOutputSecurity::META_KEY,
            ToolOutputSecurity::metaForTool('matomo_report_list')
        );
    }

    public function testAllMcpServerToolsHaveExplicitDangerousPathMappings(): void
    {
        $toolNames = [
            SiteGet::TOOL_NAME,
            SiteList::TOOL_NAME,
            SiteSearch::TOOL_NAME,
            SegmentGet::TOOL_NAME,
            SegmentList::TOOL_NAME,
            DimensionGet::TOOL_NAME,
            DimensionList::TOOL_NAME,
            GoalGet::TOOL_NAME,
            GoalList::TOOL_NAME,
            ReportList::TOOL_NAME,
            ReportMetadata::TOOL_NAME,
            ReportProcessed::TOOL_NAME,
        ];

        foreach ($toolNames as $toolName) {
            self::assertArrayHasKey($toolName, ToolOutputSecurity::DANGEROUS_PATHS_BY_TOOL);
            self::assertSame(
                ToolOutputSecurity::DANGEROUS_PATHS_BY_TOOL[$toolName],
                ToolOutputSecurity::dangerousPathsForTool($toolName)
            );
        }
    }
}
