<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Reports;

use Piwik\API\Request;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreApiModuleGatewayInterface;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\CoreApiRequestException;
use Piwik\Plugins\McpServer\Support\Errors\InfrastructureDataException;

final class CoreApiModuleGateway implements CoreApiModuleGatewayInterface
{
    /**
     * @param array<string, mixed> $apiParameters
     * @param array<string, mixed> $requestParameters
     */
    public function getProcessedReport(
        int $idSite,
        string $period,
        string $date,
        string $apiModule,
        string $apiAction,
        ?string $segment,
        array $apiParameters,
        array $requestParameters,
        int|string|null $idGoal,
        ?int $idDimension,
        ?int $idSubtable
    ): array {
        try {
            $paramOverride = [
                'idSite' => $idSite,
                'period' => $period,
                'date' => $date,
                'apiModule' => $apiModule,
                'apiAction' => $apiAction,
                'segment' => $segment ?? false,
                'apiParameters' => $apiParameters,
                'idGoal' => $idGoal ?? false,
                'language' => false,
                'showTimer' => false,
                'hideMetricsDoc' => true,
                'idSubtable' => $idSubtable ?? false,
                'showRawMetrics' => false,
                'format_metrics' => null,
                'idDimension' => $idDimension ?? false,
            ];
            $paramOverride = array_merge($paramOverride, $requestParameters);

            $report = Request::processRequest('API.getProcessedReport', $paramOverride, []);
        } catch (NoAccessException $e) {
            throw new AccessDeniedLikeException('No access to processed report.', 0, $e);
        } catch (\Throwable $e) {
            throw new CoreApiRequestException('Core API processed report request failed.', 0, $e);
        }

        return $this->requireStringKeyedArray($report, 'Report data is invalid.');
    }

    /**
     * @return array<string, mixed>
     */
    private function requireStringKeyedArray(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new InfrastructureDataException($message);
        }

        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new InfrastructureDataException($message);
            }
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
