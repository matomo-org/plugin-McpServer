<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts\Ports\Reports;

interface CoreApiModuleGatewayInterface
{
    /**
     * @param array<string, mixed> $apiParameters
     * @param array<string, mixed> $requestParameters
     * @return array<string, mixed>
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
        ?int $idSubtable,
    ): array;
}
