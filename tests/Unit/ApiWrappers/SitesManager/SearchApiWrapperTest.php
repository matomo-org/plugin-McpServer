<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\ApiWrappers\SitesManager;

use LogicException;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\SearchApiWrapper;
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\ApiWrappers\SitesManager\SiteSummaryRecord;

/**
 * @group McpServer
 * @group Plugins
 */
class SearchApiWrapperTest extends TestCase
{
    public function testSearchSitesWithViewAccessDelegatesToSummaryQueryService(): void
    {
        $expected = [
            new SiteSummaryRecord(3, 'Site Name', 'https://example.test', 'website'),
        ];

        $queryService = new class ($expected) implements SiteSummaryQueryServiceInterface {
            /** @param array<int, SiteSummaryRecord> $records */
            public function __construct(private array $records)
            {
            }

            public function getSiteSummariesForList(): array
            {
                throw new LogicException('Not used in this test.');
            }

            public function getSiteSummariesForSearch(string $search): array
            {
                return $this->records;
            }
        };

        $wrapper = new SearchApiWrapper($queryService);
        self::assertSame($expected, $wrapper->searchSitesWithViewAccess('site'));
    }
}
