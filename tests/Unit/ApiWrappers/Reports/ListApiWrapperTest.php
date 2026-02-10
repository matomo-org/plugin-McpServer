<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\ApiWrappers\Reports;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\ApiWrappers\Reports\ListApiWrapper;
use Piwik\Plugins\McpServer\Contracts\Reports\ReportSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Reports\ReportSummaryRecord;

/**
 * @group McpServer
 * @group Plugins
 */
class ListApiWrapperTest extends TestCase
{
    public function testGetReportsForSiteDelegatesToSummaryQueryService(): void
    {
        $expected = [
            new ReportSummaryRecord(
                'Actions_getPageUrls',
                'Actions',
                'getPageUrls',
                'Page URLs',
                'Actions',
                []
            ),
        ];

        $queryService = new class ($expected) implements ReportSummaryQueryServiceInterface {
            /** @param array<int, ReportSummaryRecord> $records */
            public function __construct(private array $records)
            {
            }

            public function getReportSummariesForSite(int $idSite): array
            {
                return $this->records;
            }
        };

        $wrapper = new ListApiWrapper($queryService);
        self::assertSame($expected, $wrapper->getReportsForSite(1));
    }
}
