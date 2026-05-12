<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Api;

use PHPUnit\Framework\TestCase;
use Piwik\API\Request as ApiRequest;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Support\Api\InternalApiAccessGuard;

/**
 * @group McpServer
 * @group Plugins
 */
class InternalApiAccessGuardTest extends TestCase
{
    private string $originalRootMethod = '';

    public function setUp(): void
    {
        parent::setUp();
        $this->originalRootMethod = (string) ApiRequest::getRootApiRequestMethod();
    }

    public function tearDown(): void
    {
        ApiRequest::setIsRootRequestApiRequest($this->originalRootMethod);
        parent::tearDown();
    }

    public function testAllowsNonApiHttpEntryPoint(): void
    {
        ApiRequest::setIsRootRequestApiRequest('');

        (new InternalApiAccessGuard())->assertInternalContext();

        $this->expectNotToPerformAssertions();
    }

    public function testRejectsAnotherApiMethodAsRoot(): void
    {
        ApiRequest::setIsRootRequestApiRequest('VisitsSummary.get');

        $this->expectException(NoAccessException::class);
        $this->expectExceptionMessageMatches('/only available to in-process callers/');

        (new InternalApiAccessGuard())->assertInternalContext();
    }

    public function testRejectsDirectHttpEntryToInternalCatalog(): void
    {
        ApiRequest::setIsRootRequestApiRequest('McpServer.getInternalToolCatalog');

        $this->expectException(NoAccessException::class);

        (new InternalApiAccessGuard())->assertInternalContext();
    }

    public function testRejectsDirectHttpEntryToInternalCall(): void
    {
        ApiRequest::setIsRootRequestApiRequest('McpServer.callInternalTool');

        $this->expectException(NoAccessException::class);

        (new InternalApiAccessGuard())->assertInternalContext();
    }

    public function testRejectsBulkRequestProxy(): void
    {
        ApiRequest::setIsRootRequestApiRequest('API.getBulkRequest');

        $this->expectException(NoAccessException::class);

        (new InternalApiAccessGuard())->assertInternalContext();
    }

    public function testRejectsProcessedReportProxy(): void
    {
        ApiRequest::setIsRootRequestApiRequest('API.getProcessedReport');

        $this->expectException(NoAccessException::class);

        (new InternalApiAccessGuard())->assertInternalContext();
    }
}
