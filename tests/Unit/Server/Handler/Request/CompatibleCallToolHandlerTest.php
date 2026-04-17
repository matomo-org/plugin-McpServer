<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Server\Handler\Request;

use Matomo\Dependencies\McpServer\Mcp\Capability\Registry;
use Matomo\Dependencies\McpServer\Mcp\Capability\Registry\ReferenceHandler;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Response;
use Matomo\Dependencies\McpServer\Mcp\Schema\Request\CallToolRequest;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\InMemorySessionStore;
use Matomo\Dependencies\McpServer\Mcp\Server\Session\Session;
use Matomo\Dependencies\McpServer\Symfony\Component\Uid\Uuid;
use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\McpTools\ReportMetadata;
use Piwik\Plugins\McpServer\Server\Handler\Request\CompatibleCallToolHandler;
use Psr\Log\NullLogger;

/**
 * @group McpServer
 * @group Plugins
 */
class CompatibleCallToolHandlerTest extends TestCase
{
    public function testAcceptsEmptyListApiParametersForReportMetadataCompatibilityPath(): void
    {
        $registry = new Registry();
        $capturedApiParameters = null;
        $request = (new CallToolRequest(ReportMetadata::TOOL_NAME, [
            'idSite' => 1,
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
            'apiParameters' => [],
        ]))->withId('compat-empty-list');

        $registry->registerTool($this->createTool(ReportMetadata::TOOL_NAME), static function (
            int $idSite,
            string $apiModule,
            string $apiAction,
            array $apiParameters,
        ) use (&$capturedApiParameters): array {
            $capturedApiParameters = $apiParameters;

            return [
                'idSite' => $idSite,
                'apiModule' => $apiModule,
                'apiAction' => $apiAction,
                'apiParameters' => $apiParameters,
            ];
        });

        $handler = new CompatibleCallToolHandler($registry, new ReferenceHandler(), new NullLogger());
        $response = $handler->handle($request, $this->createSession('87f14d0f-7d95-4a76-b2db-bf0f1ca6f3a1'));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);
        self::assertSame([], $capturedApiParameters);
    }

    public function testRejectsNonEmptyListApiParametersAgainstObjectOnlySchema(): void
    {
        $registry = new Registry();
        $request = (new CallToolRequest(ReportMetadata::TOOL_NAME, [
            'idSite' => 1,
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
            'apiParameters' => ['flat'],
        ]))->withId('compat-non-empty-list');

        $registry->registerTool($this->createTool(ReportMetadata::TOOL_NAME), static function (): array {
            return [];
        });

        $handler = new CompatibleCallToolHandler($registry, new ReferenceHandler(), new NullLogger());
        $error = $handler->handle($request, $this->createSession('e6b0fd2f-24a8-4a74-bf74-ec56d99963dd'));

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::INVALID_PARAMS, $error->code);
        self::assertStringContainsString('Invalid parameters for tool', $error->message);
    }

    private function createTool(string $name): \Matomo\Dependencies\McpServer\Mcp\Schema\Tool
    {
        return new \Matomo\Dependencies\McpServer\Mcp\Schema\Tool($name, [
            'type' => 'object',
            'properties' => [
                'idSite' => ['type' => 'integer'],
                'apiModule' => ['type' => 'string'],
                'apiAction' => ['type' => 'string'],
                'apiParameters' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['idSite', 'apiModule', 'apiAction'],
        ], null, null);
    }

    private function createSession(string $uuid): Session
    {
        return new Session(new InMemorySessionStore(), Uuid::fromString($uuid));
    }
}
