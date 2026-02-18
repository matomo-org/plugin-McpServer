<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Services\Reports;

use Piwik\Date;
use Piwik\Plugins\API\ProcessedReport;
use Piwik\Plugins\McpServer\Contracts\Ports\Reports\CoreProcessedReportGatewayInterface;
use Piwik\Plugins\McpServer\Support\Errors\InfrastructureDataException;

final class CoreProcessedReportGateway implements CoreProcessedReportGatewayInterface
{
    public function __construct(private ProcessedReport $processedReport)
    {
    }

    public function getReportMetadataByUniqueId(int $idSite, string $reportUniqueId): array
    {
        $metadata = $this->processedReport->getReportMetadataByUniqueId($idSite, $reportUniqueId);
        return $this->requireStringKeyedArray($metadata, 'Report not found.');
    }

    public function getReportMetadata(
        int $idSite,
        string $period,
        Date|string|bool $date,
        bool $hideMetricsDoc,
        bool $showSubtableReports
    ): array {
        // Matomo accepts range strings (e.g. "YYYY-MM-DD,YYYY-MM-DD") for period=range at runtime.
        // ProcessedReport phpdoc does not include string, so suppress static mismatch at this boundary.
        $reports = $this->processedReport->getReportMetadata(
            $idSite,
            $period,
            $date, // @phpstan-ignore argument.type
            $hideMetricsDoc,
            $showSubtableReports
        );

        return $this->requireListOfStringKeyedArrays($reports, 'Report metadata data is invalid.');
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
