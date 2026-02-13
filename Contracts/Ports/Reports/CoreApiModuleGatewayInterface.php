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
     */
    public function getProcessedReport(
        int $idSite,
        string $period,
        string $date,
        string $apiModule,
        string $apiAction,
        ?string $segment,
        array $apiParameters,
        int|string|null $idGoal,
        ?int $idDimension,
        ?int $idSubtable
    ): mixed;
}
