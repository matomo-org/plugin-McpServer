<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Matomo\Dependencies\McpServer\Mcp\Server\Session\SessionStoreInterface;
use Piwik\DI;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\CoreCustomDimensionsGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Dimensions\DimensionSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\CoreGoalsGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Goals\GoalSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreApiModuleGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportMetadataQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportProcessedQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\ReportSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\StrictSegmentPolicyServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\TranslatorContextRunnerInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\CoreSegmentEditorGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Segments\SegmentSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\CoreSitesManagerGatewayInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteDetailQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\Sites\SiteSummaryQueryServiceInterface;
use Piwik\Plugins\McpServer\Contracts\Ports\System\PluginCapabilityGatewayInterface;
use Piwik\Plugins\McpServer\Services\Dimensions\CoreCustomDimensionsGateway;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionDetailQueryService;
use Piwik\Plugins\McpServer\Services\Dimensions\DimensionSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Goals\CoreGoalsGateway;
use Piwik\Plugins\McpServer\Services\Goals\GoalDetailQueryService;
use Piwik\Plugins\McpServer\Services\Goals\GoalSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Reports\CoreApiModuleGateway;
use Piwik\Plugins\McpServer\Services\Reports\CoreProcessedReportGateway;
use Piwik\Plugins\McpServer\Services\Reports\ReportMetadataQueryService;
use Piwik\Plugins\McpServer\Services\Reports\ReportProcessedQueryService;
use Piwik\Plugins\McpServer\Services\Reports\ReportSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Reports\StrictSegmentPolicyService;
use Piwik\Plugins\McpServer\Services\Reports\TranslatorContextRunner;
use Piwik\Plugins\McpServer\Services\Segments\CoreSegmentEditorGateway;
use Piwik\Plugins\McpServer\Services\Segments\SegmentDetailQueryService;
use Piwik\Plugins\McpServer\Services\Segments\SegmentSummaryQueryService;
use Piwik\Plugins\McpServer\Services\Sites\CoreSitesManagerGateway;
use Piwik\Plugins\McpServer\Services\Sites\SiteDetailQueryService;
use Piwik\Plugins\McpServer\Services\Sites\SiteSummaryQueryService;
use Piwik\Plugins\McpServer\Services\System\PluginCapabilityGateway;
use Piwik\Plugins\McpServer\Session\DbSessionStore;

return [
    CoreApiModuleGatewayInterface::class => DI::autowire(CoreApiModuleGateway::class),
    CoreCustomDimensionsGatewayInterface::class => DI::autowire(CoreCustomDimensionsGateway::class),
    CoreGoalsGatewayInterface::class => DI::autowire(CoreGoalsGateway::class),
    CoreProcessedReportGatewayInterface::class => DI::autowire(CoreProcessedReportGateway::class),
    CoreSegmentEditorGatewayInterface::class => DI::autowire(CoreSegmentEditorGateway::class),
    CoreSitesManagerGatewayInterface::class => DI::autowire(CoreSitesManagerGateway::class),
    DimensionDetailQueryServiceInterface::class => DI::autowire(DimensionDetailQueryService::class),
    DimensionSummaryQueryServiceInterface::class => DI::autowire(DimensionSummaryQueryService::class),
    GoalDetailQueryServiceInterface::class => DI::autowire(GoalDetailQueryService::class),
    GoalSummaryQueryServiceInterface::class => DI::autowire(GoalSummaryQueryService::class),
    PluginCapabilityGatewayInterface::class => DI::autowire(PluginCapabilityGateway::class),
    ReportMetadataQueryServiceInterface::class => DI::autowire(ReportMetadataQueryService::class),
    ReportProcessedQueryServiceInterface::class => DI::autowire(ReportProcessedQueryService::class),
    ReportSummaryQueryServiceInterface::class => DI::autowire(ReportSummaryQueryService::class),
    StrictSegmentPolicyServiceInterface::class => DI::autowire(StrictSegmentPolicyService::class),
    SegmentDetailQueryServiceInterface::class => DI::autowire(SegmentDetailQueryService::class),
    SegmentSummaryQueryServiceInterface::class => DI::autowire(SegmentSummaryQueryService::class),
    SessionStoreInterface::class => DI::autowire(DbSessionStore::class),
    SiteDetailQueryServiceInterface::class => DI::autowire(SiteDetailQueryService::class),
    SiteSummaryQueryServiceInterface::class => DI::autowire(SiteSummaryQueryService::class),
    TranslatorContextRunnerInterface::class => DI::autowire(TranslatorContextRunner::class),
];
