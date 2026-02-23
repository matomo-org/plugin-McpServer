<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Services\Reports;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Services\Reports\CoreProcessedReportGateway;
use Piwik\Plugins\McpServer\Support\Errors\InfrastructureDataException;

/**
 * @group McpServer
 * @group Plugins
 */
class CoreProcessedReportGatewayTest extends TestCase
{
    public function testGetReportMetadataByUniqueIdReturnsStringKeyedArray(): void
    {
        $gateway = new CoreProcessedReportGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return [
                    ['uniqueId' => 'Actions_getPageUrls', 'module' => 'Actions'],
                ];
            }
        );
        $actual = $gateway->getReportMetadataByUniqueId(1, 'Actions_getPageUrls');

        self::assertSame('Actions_getPageUrls', $actual['uniqueId'] ?? null);
        self::assertSame('Actions', $actual['module'] ?? null);
    }

    public function testGetReportMetadataByUniqueIdRejectsInvalidPayloadShape(): void
    {
        $gateway = new CoreProcessedReportGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return [['invalid-indexed-row']];
            }
        );

        $this->expectException(InfrastructureDataException::class);
        $this->expectExceptionMessage('Report not found.');
        $gateway->getReportMetadataByUniqueId(1, 'Actions_getPageUrls');
    }

    public function testGetReportMetadataReturnsListOfStringKeyedRows(): void
    {
        $gateway = new CoreProcessedReportGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return [
                    ['uniqueId' => 'Actions_getPageUrls', 'module' => 'Actions'],
                    ['uniqueId' => 'VisitsSummary_get', 'module' => 'VisitsSummary'],
                ];
            }
        );
        $actual = $gateway->getReportMetadata(1, 'day', false, false, false);

        self::assertCount(2, $actual);
        self::assertSame('Actions_getPageUrls', $actual[0]['uniqueId'] ?? null);
        self::assertSame('VisitsSummary_get', $actual[1]['uniqueId'] ?? null);
    }

    public function testGetReportMetadataRejectsInvalidTopLevelShape(): void
    {
        $gateway = new CoreProcessedReportGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): string {
                return 'invalid';
            }
        );

        $this->expectException(InfrastructureDataException::class);
        $this->expectExceptionMessage('Report metadata data is invalid.');
        $gateway->getReportMetadata(1, 'day', false, false, false);
    }

    public function testGetReportMetadataRejectsInvalidRowShape(): void
    {
        $gateway = new CoreProcessedReportGateway(
            static function (string $method, array $paramOverride, array $defaultRequest): array {
                return [
                    ['uniqueId' => 'Actions_getPageUrls'],
                    ['invalid-indexed-row'],
                ];
            }
        );

        $this->expectException(InfrastructureDataException::class);
        $this->expectExceptionMessage('Report metadata data is invalid.');
        $gateway->getReportMetadata(1, 'day', false, false, false);
    }
}
