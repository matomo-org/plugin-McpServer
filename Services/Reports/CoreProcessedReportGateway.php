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
use Piwik\Date;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;
use Piwik\Plugins\McpServer\Support\Errors\InfrastructureDataException;

final class CoreProcessedReportGateway implements CoreProcessedReportGatewayInterface
{
    /** @var callable|null */
    private $requestProcessor;

    public function __construct(?callable $requestProcessor = null)
    {
        $this->requestProcessor = $requestProcessor;
    }

    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
    {
        $reports = $this->processRequest([
            'idSite' => (string) $idSite,
            'period' => false,
            'date' => false,
            'hideMetricsDoc' => false,
            'showSubtableReports' => false,
        ]);
        $reports = $this->requireListOfStringKeyedArrays($reports, 'Report not found.');

        foreach ($reports as $report) {
            if (($report['uniqueId'] ?? null) === $reportUniqueId) {
                return $report;
            }
        }

        throw new InfrastructureDataException('Report not found.');
    }

    public function getReportMetadata(
        int $idSite,
        string $period,
        Date|string|bool $date,
        bool $hideMetricsDoc,
        bool $showSubtableReports,
    ): array {
        $reports = $this->processRequest([
            'idSite' => (string) $idSite,
            'period' => $period,
            'date' => $date,
            'hideMetricsDoc' => $hideMetricsDoc,
            'showSubtableReports' => $showSubtableReports,
        ]);

        return $this->requireListOfStringKeyedArrays($reports, 'Report metadata data is invalid.');
    }

    /**
     * @param array<string, mixed> $paramOverride
     */
    private function processRequest(array $paramOverride): mixed
    {
        if ($this->requestProcessor !== null) {
            return ($this->requestProcessor)('API.getReportMetadata', $paramOverride, []);
        }

        return Request::processRequest('API.getReportMetadata', $paramOverride, []);
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

    /**
     * @return list<array<string, mixed>>
     */
    private function requireListOfStringKeyedArrays(mixed $value, string $message): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new InfrastructureDataException($message);
        }

        $normalized = [];
        foreach ($value as $item) {
            $normalized[] = $this->requireStringKeyedArray($item, $message);
        }

        return $normalized;
    }
}
