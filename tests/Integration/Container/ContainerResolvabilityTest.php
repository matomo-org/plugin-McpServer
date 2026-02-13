<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\Container;

use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Piwik\Container\StaticContainer;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\McpServerFactory;
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
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionDetailQueryService;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Goals\GoalDetailQueryService;
use Piwik\Plugins\McpServer\Services\Goals\GoalRevenueNormalizer;
use Piwik\Plugins\McpServer\Services\Goals\GoalSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Reports\ReportMetadataQueryService;
use Piwik\Plugins\McpServer\Services\Reports\ReportProcessedQueryService;
use Piwik\Plugins\McpServer\Services\Reports\ReportSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Segments\SegmentDetailQueryService;
use Piwik\Plugins\McpServer\Services\Segments\SegmentSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Sites\SiteDetailQueryService;
use Piwik\Plugins\McpServer\Services\Sites\SiteSummaryQueryService;
use Piwik\Plugins\McpServer\Session\DbSessionStore;
use Piwik\Plugins\McpServer\Session\DbSessionTable;
use Piwik\Plugins\McpServer\Support\Pagination\CursorPaginator;
use Piwik\Plugins\McpServer\Support\RequestScope\GetRequestScopeMutator;
use Piwik\Plugins\McpServer\Support\RequestScope\GetRequestScopeMutatorInterface;
use Piwik\Plugins\McpServer\Support\Tooling\PaginatedCollectionResponder;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;
use Throwable;

/**
 * @group McpServer
 * @group Plugins
 */
class ContainerResolvabilityTest extends IntegrationTestCase
{
    /**
     * @dataProvider provideResolvableServices
     * @param class-string<object> $serviceId
     * @param class-string<object> $expectedClass
     */
    public function testContainerResolvesExpectedService(string $serviceId, string $expectedClass): void
    {
        try {
            $resolved = StaticContainer::get($serviceId);
        } catch (Throwable $e) {
            self::fail(
                sprintf(
                    "Container failed to resolve '%s': %s\n%s",
                    $serviceId,
                    $e->getMessage(),
                    $e->getTraceAsString()
                )
            );
        }

        self::assertInstanceOf(
            $expectedClass,
            $resolved,
            sprintf("Service '%s' did not resolve to '%s'.", $serviceId, $expectedClass)
        );
    }

    /**
     * @return array<string, array{0: class-string<object>, 1: class-string<object>}>
     */
    public function provideResolvableServices(): array
    {
        return [
            'interface.dimension.detail' => [
                DimensionDetailQueryServiceInterface::class,
                DimensionDetailQueryService::class,
            ],
            'interface.dimension.summary' => [
                DimensionSummaryQueryServiceInterface::class,
                DimensionSummaryQueryService::class,
            ],
            'interface.goal.detail' => [GoalDetailQueryServiceInterface::class, GoalDetailQueryService::class],
            'interface.goal.summary' => [GoalSummaryQueryServiceInterface::class, GoalSummaryQueryService::class],
            'interface.report.metadata' => [
                ReportMetadataQueryServiceInterface::class,
                ReportMetadataQueryService::class,
            ],
            'interface.report.processed' => [
                ReportProcessedQueryServiceInterface::class,
                ReportProcessedQueryService::class,
            ],
            'interface.report.summary' => [
                ReportSummaryQueryServiceInterface::class,
                ReportSummaryQueryService::class,
            ],
            'interface.segment.detail' => [SegmentDetailQueryServiceInterface::class, SegmentDetailQueryService::class],
            'interface.segment.summary' => [
                SegmentSummaryQueryServiceInterface::class,
                SegmentSummaryQueryService::class,
            ],
            'interface.site.detail' => [SiteDetailQueryServiceInterface::class, SiteDetailQueryService::class],
            'interface.site.summary' => [SiteSummaryQueryServiceInterface::class, SiteSummaryQueryService::class],
            'interface.request.scope' => [GetRequestScopeMutatorInterface::class, GetRequestScopeMutator::class],
            'interface.session.store' => [SessionStoreInterface::class, DbSessionStore::class],

            'tool.dimension.get' => [DimensionGet::class, DimensionGet::class],
            'tool.dimension.list' => [DimensionList::class, DimensionList::class],
            'tool.goal.get' => [GoalGet::class, GoalGet::class],
            'tool.goal.list' => [GoalList::class, GoalList::class],
            'tool.report.list' => [ReportList::class, ReportList::class],
            'tool.report.metadata' => [ReportMetadata::class, ReportMetadata::class],
            'tool.report.processed' => [ReportProcessed::class, ReportProcessed::class],
            'tool.segment.get' => [SegmentGet::class, SegmentGet::class],
            'tool.segment.list' => [SegmentList::class, SegmentList::class],
            'tool.site.get' => [SiteGet::class, SiteGet::class],
            'tool.site.list' => [SiteList::class, SiteList::class],
            'tool.site.search' => [SiteSearch::class, SiteSearch::class],

            'service.dimension.detail' => [DimensionDetailQueryService::class, DimensionDetailQueryService::class],
            'service.dimension.summary' => [DimensionSummaryQueryService::class, DimensionSummaryQueryService::class],
            'service.goal.detail' => [GoalDetailQueryService::class, GoalDetailQueryService::class],
            'service.goal.revenue.normalizer' => [GoalRevenueNormalizer::class, GoalRevenueNormalizer::class],
            'service.goal.summary' => [GoalSummaryQueryService::class, GoalSummaryQueryService::class],
            'service.report.metadata' => [ReportMetadataQueryService::class, ReportMetadataQueryService::class],
            'service.report.processed' => [ReportProcessedQueryService::class, ReportProcessedQueryService::class],
            'service.report.summary' => [ReportSummaryQueryService::class, ReportSummaryQueryService::class],
            'service.segment.detail' => [SegmentDetailQueryService::class, SegmentDetailQueryService::class],
            'service.segment.summary' => [SegmentSummaryQueryService::class, SegmentSummaryQueryService::class],
            'service.site.detail' => [SiteDetailQueryService::class, SiteDetailQueryService::class],
            'service.site.summary' => [SiteSummaryQueryService::class, SiteSummaryQueryService::class],

            'support.pagination.cursor.paginator' => [CursorPaginator::class, CursorPaginator::class],
            'support.request.scope.get.mutator' => [GetRequestScopeMutator::class, GetRequestScopeMutator::class],
            'support.tooling.paginated.responder' => [
                PaginatedCollectionResponder::class,
                PaginatedCollectionResponder::class,
            ],
            'composition.mcp.server.factory' => [McpServerFactory::class, McpServerFactory::class],
            'composition.session.db.store' => [DbSessionStore::class, DbSessionStore::class],
            'composition.session.db.table' => [DbSessionTable::class, DbSessionTable::class],
        ];
    }
}
