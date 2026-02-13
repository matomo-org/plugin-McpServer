<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Piwik\DI;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreApiModuleGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Controller;
use Piwik\Plugins\McpServer\McpServerFactory;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionDetailQueryService;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Goals\GoalDetailQueryService;
use Piwik\Plugins\McpServer\Services\Goals\GoalSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Reports\CoreApiModuleGateway;
use Piwik\Plugins\McpServer\Services\Reports\CoreProcessedReportGateway;
use Piwik\Plugins\McpServer\Services\Reports\ReportMetadataQueryService;
use Piwik\Plugins\McpServer\Services\Reports\ReportProcessedQueryService;
use Piwik\Plugins\McpServer\Services\Reports\ReportSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Reports\TranslatorContextRunner;
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
use Piwik\Plugins\McpServer\Tasks;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;

return [
    DimensionDetailQueryServiceInterface::class => DI::autowire(DimensionDetailQueryService::class),
    DimensionSummaryQueryServiceInterface::class => DI::autowire(DimensionSummaryQueryService::class),
    GoalDetailQueryServiceInterface::class => DI::autowire(GoalDetailQueryService::class),
    GoalSummaryQueryServiceInterface::class => DI::autowire(GoalSummaryQueryService::class),
    CoreApiModuleGatewayInterface::class => DI::autowire(CoreApiModuleGateway::class),
    CoreProcessedReportGatewayInterface::class => DI::autowire(CoreProcessedReportGateway::class),
    ReportMetadataQueryServiceInterface::class => DI::autowire(ReportMetadataQueryService::class),
    ReportProcessedQueryServiceInterface::class => DI::autowire(ReportProcessedQueryService::class),
    ReportSummaryQueryServiceInterface::class => DI::autowire(ReportSummaryQueryService::class),
    SegmentDetailQueryServiceInterface::class => DI::autowire(SegmentDetailQueryService::class),
    SegmentSummaryQueryServiceInterface::class => DI::autowire(SegmentSummaryQueryService::class),
    SiteDetailQueryServiceInterface::class => DI::autowire(SiteDetailQueryService::class),
    SiteSummaryQueryServiceInterface::class => DI::autowire(SiteSummaryQueryService::class),
    TranslatorContextRunnerInterface::class => DI::autowire(TranslatorContextRunner::class),
    // ReportProcessedQueryService depends on this interface transitively.
    GetRequestScopeMutatorInterface::class => DI::autowire(GetRequestScopeMutator::class),
    SessionStoreInterface::class => DI::autowire(DbSessionStore::class),

    CoreApiModuleGateway::class => DI::autowire(),
    CoreProcessedReportGateway::class => DI::autowire(),
    DimensionDetailQueryService::class => DI::autowire(),
    DimensionSummaryQueryService::class => DI::autowire(),
    GoalDetailQueryService::class => DI::autowire(),
    GoalSummaryQueryService::class => DI::autowire(),
    ReportMetadataQueryService::class => DI::autowire(),
    ReportProcessedQueryService::class => DI::autowire(),
    ReportSummaryQueryService::class => DI::autowire(),
    SegmentDetailQueryService::class => DI::autowire(),
    SegmentSummaryQueryService::class => DI::autowire(),
    SiteDetailQueryService::class => DI::autowire(),
    SiteSummaryQueryService::class => DI::autowire(),
    TranslatorContextRunner::class => DI::autowire(),

    GetRequestScopeMutator::class => DI::autowire(),
    // Explicit support bindings keep container composition as the construction source of truth.
    PaginatedCollectionResponder::class => DI::autowire(),
    CursorPaginator::class => DI::autowire(),
    McpServerFactory::class => DI::autowire(),
    Controller::class => DI::autowire(),
    Tasks::class => DI::autowire(),
    DbSessionStore::class => DI::autowire(),
    DbSessionTable::class => DI::autowire(),
];
