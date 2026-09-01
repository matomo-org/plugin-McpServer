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

        $handler = $this->createHandler($registry);
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

        $handler = $this->createHandler($registry);
        $error = $handler->handle($request, $this->createSession('e6b0fd2f-24a8-4a74-bf74-ec56d99963dd'));

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::INVALID_PARAMS, $error->code);
        self::assertStringContainsString('Invalid parameters for tool', $error->message);
    }

    /**
     * A stringified integer on a tool that has an intake profile. The profile does not retype:
     * `coerceIntegerStringsForValidation()` gets `'1'` past the `integer` property and the SDK's
     * reference handler casts it against the reflected `int $idSite` on dispatch.
     * {@see testCoercesPureIntegerStringForToolWithoutIntakeProfile} is the same assertion with no
     * profile in play.
     */
    public function testAcceptsPureIntegerStringForIntegerPropertyOnAProfiledTool(): void
    {
        $registry = new Registry();
        $capturedIdSite = null;
        $request = (new CallToolRequest(ReportMetadata::TOOL_NAME, [
            'idSite' => '1',
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
            'apiParameters' => [],
        ]))->withId('coerce-int-string');

        $registry->registerTool($this->createTool(ReportMetadata::TOOL_NAME), static function (
            int $idSite,
            string $apiModule,
            string $apiAction,
            array $apiParameters,
        ) use (&$capturedIdSite): array {
            $capturedIdSite = $idSite;

            return ['idSite' => $idSite];
        });

        $handler = $this->createHandler($registry);
        $response = $handler->handle($request, $this->createSession('c0e7a2b1-2f3d-4a5b-8c9d-0e1f2a3b4c5d'));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);
        self::assertSame(1, $capturedIdSite);
    }

    /**
     * The same stringified integer with no intake profile in play: `toolClasses` is left empty, so
     * `IntakeNormalizer` never runs and only `coerceIntegerStringsForValidation()` can get `'1'`
     * past the `integer` property, and only the SDK's reference handler can turn it into the
     * `int $idSite` the captured argument asserts.
     *
     * `apiParameters` is omitted because the empty-list substitution is profile-driven.
     */
    public function testCoercesPureIntegerStringForToolWithoutIntakeProfile(): void
    {
        $registry = new Registry();
        $capturedIdSite = null;
        $request = (new CallToolRequest(ReportMetadata::TOOL_NAME, [
            'idSite' => '1',
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
        ]))->withId('coerce-int-string-unprofiled');

        $registry->registerTool($this->createTool(ReportMetadata::TOOL_NAME), static function (
            int $idSite,
            string $apiModule,
            string $apiAction,
        ) use (&$capturedIdSite): array {
            $capturedIdSite = $idSite;

            return [];
        });

        $handler = new CompatibleCallToolHandler($registry, new ReferenceHandler());
        $response = $handler->handle($request, $this->createSession('a4c1e6f5-6d7b-8e9f-2a3b-4c5d6e7f8091'));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);
        self::assertSame(1, $capturedIdSite);
    }

    public function testRejectsNonIntegerStringForIntegerProperty(): void
    {
        $registry = new Registry();
        $request = (new CallToolRequest(ReportMetadata::TOOL_NAME, [
            'idSite' => '1.5',
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
            'apiParameters' => [],
        ]))->withId('reject-non-int-string');

        $registry->registerTool($this->createTool(ReportMetadata::TOOL_NAME), static function (): array {
            return [];
        });

        $handler = $this->createHandler($registry);
        $error = $handler->handle($request, $this->createSession('d1f8b3c2-3a4e-5b6c-9d0e-1f2a3b4c5d6e'));

        self::assertInstanceOf(Error::class, $error);
        self::assertSame(Error::INVALID_PARAMS, $error->code);
        self::assertStringContainsString('Invalid parameters for tool', $error->message);
    }

    public function testDoesNotCoerceStringWithinOneOfUnionButStillValidates(): void
    {
        $registry = new Registry();
        $capturedIdGoal = 'unset';

        // Union parameters are intentionally left untouched: the string form already passes
        // validation and the handler receives the raw argument, so "1" stays a string.
        $registry->registerTool($this->createTool(ReportMetadata::TOOL_NAME), static function (
            int $idSite,
            string $apiModule,
            string $apiAction,
            array $apiParameters,
            int|string|null $idGoal = null,
        ) use (&$capturedIdGoal): array {
            $capturedIdGoal = $idGoal;

            return [];
        });
        $handler = $this->createHandler($registry);

        $request = (new CallToolRequest(ReportMetadata::TOOL_NAME, [
            'idSite' => 1,
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
            'apiParameters' => [],
            'idGoal' => '1',
        ]))->withId('union-numeric');

        $response = $handler->handle($request, $this->createSession('e2a9c4d3-4b5f-6c7d-0e1f-2a3b4c5d6e7f'));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);
        self::assertSame('1', $capturedIdGoal);

        $nonNumericRequest = (new CallToolRequest(ReportMetadata::TOOL_NAME, [
            'idSite' => 1,
            'apiModule' => 'Actions',
            'apiAction' => 'getPageUrls',
            'apiParameters' => [],
            'idGoal' => 'ecommerceOrder',
        ]))->withId('union-non-numeric');

        $response = $handler->handle($nonNumericRequest, $this->createSession('f3b0d5e4-5c6a-7d8e-1f2a-3b4c5d6e7f80'));

        self::assertInstanceOf(Response::class, $response);
        self::assertInstanceOf(CallToolResult::class, $response->result);
        self::assertFalse($response->result->isError);
        self::assertSame('ecommerceOrder', $capturedIdGoal);
    }

    /**
     * The class map stands in for what McpServerFactory collects while registering the real tool
     * objects. These cases register a reduced schema under the metadata tool's name, so the map
     * has to name its class for the tool's intake profile to apply.
     */
    private function createHandler(Registry $registry): CompatibleCallToolHandler
    {
        return new CompatibleCallToolHandler(
            $registry,
            new ReferenceHandler(),
            toolClasses: [ReportMetadata::TOOL_NAME => ReportMetadata::class],
        );
    }

    private function createTool(string $name): \Matomo\Dependencies\McpServer\Mcp\Schema\Tool
    {
        return new \Matomo\Dependencies\McpServer\Mcp\Schema\Tool($name, null, [
            'type' => 'object',
            'properties' => [
                'idSite' => ['type' => 'integer'],
                'apiModule' => ['type' => 'string'],
                'apiAction' => ['type' => 'string'],
                'apiParameters' => [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
                'idGoal' => [
                    'oneOf' => [
                        ['type' => 'integer'],
                        ['type' => 'string'],
                    ],
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
