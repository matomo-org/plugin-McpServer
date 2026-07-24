<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration\McpTools;

use Matomo\Dependencies\McpServer\Mcp\Schema\Content\TextContent;
use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Matomo\Dependencies\McpServer\Mcp\Schema\Result\CallToolResult;
use Piwik\Plugins\McpServer\McpTools\SegmentGet;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Plugins\SegmentEditor\API as SegmentEditorApi;
use Piwik\Tests\Framework\Fixture;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class SegmentGetTest extends IntegrationTestCase
{
    private int $idSite = 0;
    private int $idSegmentAlpha = 0;
    private int $idSegmentBeta = 0;
    private int $idSegmentGamma = 0;
    private int $idSegmentDelta = 0;
    private int $idSegmentEpsilon = 0;
    private string $segmentNameAlpha = '';
    private string $segmentNameShared = '';
    private string $segmentDefinitionShared = '';

    public function setUp(): void
    {
        parent::setUp();

        $this->idSite = Fixture::createWebsite(
            '2010-01-01 00:00:00',
            0,
            'MCP Segment Get Test Site',
            'https://segment-get.test',
        );

        $suffix = substr(hash('sha256', __METHOD__ . microtime(true)), 0, 8);
        $this->segmentNameAlpha = 'MCP Segment Alpha ' . $suffix;
        $this->segmentNameShared = 'MCP Segment Shared ' . $suffix;
        $this->segmentDefinitionShared = 'browserCode==FF';

        $this->idSegmentAlpha = SegmentEditorApi::getInstance()->add(
            $this->segmentNameAlpha,
            'countryCode==de',
            $this->idSite,
        );

        $this->idSegmentBeta = SegmentEditorApi::getInstance()->add(
            $this->segmentNameShared,
            'countryCode==fr',
            $this->idSite,
        );

        $this->idSegmentGamma = SegmentEditorApi::getInstance()->add(
            $this->segmentNameShared,
            'countryCode==ch',
            $this->idSite,
        );

        $this->idSegmentDelta = SegmentEditorApi::getInstance()->add(
            'MCP Segment Delta ' . $suffix,
            $this->segmentDefinitionShared,
            $this->idSite,
        );

        $this->idSegmentEpsilon = SegmentEditorApi::getInstance()->add(
            'MCP Segment Epsilon ' . $suffix,
            $this->segmentDefinitionShared,
            $this->idSite,
        );
    }

    public function testReturnsSegmentByIdSegment(): void
    {
        $result = $this->callTool([
            'idSite' => $this->idSite,
            'idSegment' => $this->idSegmentAlpha,
        ]);

        self::assertFalse($result->isError);
        self::assertSame($this->idSegmentAlpha, $result->structuredContent['idsegment'] ?? null);
        self::assertSame($this->segmentNameAlpha, $result->structuredContent['name'] ?? null);
        self::assertSame('countryCode==de', $result->structuredContent['definition'] ?? null);
    }

    public function testReturnsSegmentByName(): void
    {
        $result = $this->callTool([
            'idSite' => $this->idSite,
            'name' => $this->segmentNameAlpha,
        ]);

        self::assertFalse($result->isError);
        self::assertSame($this->idSegmentAlpha, $result->structuredContent['idsegment'] ?? null);
    }

    public function testReturnsSegmentByDefinition(): void
    {
        $result = $this->callTool([
            'idSite' => $this->idSite,
            'definition' => 'countryCode==de',
        ]);

        self::assertFalse($result->isError);
        self::assertSame($this->idSegmentAlpha, $result->structuredContent['idsegment'] ?? null);
    }

    public function testRejectsInvalidSelectorCombinationAtSchemaLevel(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SegmentGet::TOOL_NAME,
            [
                'idSite' => $this->idSite,
                'idSegment' => $this->idSegmentAlpha,
                'name' => $this->segmentNameAlpha,
            ],
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SegmentGet::TOOL_NAME . "':",
            $message->message,
        );
    }

    public function testRejectsMissingSelectorAtSchemaLevel(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SegmentGet::TOOL_NAME,
            ['idSite' => $this->idSite],
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SegmentGet::TOOL_NAME . "':",
            $message->message,
        );
    }

    public function testRejectsIdSegmentAndDefinitionAtSchemaLevel(): void
    {
        $this->assertInvalidSchemaArguments([
            'idSite' => $this->idSite,
            'idSegment' => $this->idSegmentAlpha,
            'definition' => 'countryCode==de',
        ]);
    }

    public function testRejectsNameAndDefinitionAtSchemaLevel(): void
    {
        $this->assertInvalidSchemaArguments([
            'idSite' => $this->idSite,
            'name' => $this->segmentNameAlpha,
            'definition' => 'countryCode==de',
        ]);
    }

    public function testRejectsAllSelectorsAtSchemaLevel(): void
    {
        $this->assertInvalidSchemaArguments([
            'idSite' => $this->idSite,
            'idSegment' => $this->idSegmentAlpha,
            'name' => $this->segmentNameAlpha,
            'definition' => 'countryCode==de',
        ]);
    }

    public function testReturnsErrorWhenNoSegmentMatches(): void
    {
        $result = $this->callTool([
            'idSite' => $this->idSite,
            'idSegment' => 9999999,
        ]);

        self::assertTrue($result->isError);
        self::assertSame('Segment not found.', $this->extractFirstTextContent($result));
    }

    public function testReturnsErrorWhenNameMatchIsAmbiguous(): void
    {
        $result = $this->callTool([
            'idSite' => $this->idSite,
            'name' => $this->segmentNameShared,
        ]);

        self::assertTrue($result->isError);
        self::assertSame('Multiple segments matched. Provide idSegment.', $this->extractFirstTextContent($result));
        self::assertNotSame($this->idSegmentBeta, $this->idSegmentGamma);
    }

    public function testReturnsErrorWhenDefinitionMatchIsAmbiguous(): void
    {
        $result = $this->callTool([
            'idSite' => $this->idSite,
            'definition' => $this->segmentDefinitionShared,
        ]);

        self::assertTrue($result->isError);
        self::assertSame('Multiple segments matched. Provide idSegment.', $this->extractFirstTextContent($result));
        self::assertNotSame($this->idSegmentDelta, $this->idSegmentEpsilon);
    }

    public function testMasksNoAccessAsNotFound(): void
    {
        McpAuthTestHelper::asNoAccessUser(function (): void {
            $result = $this->callTool([
                'idSite' => $this->idSite,
                'idSegment' => $this->idSegmentAlpha,
            ]);

            self::assertTrue($result->isError);
            self::assertSame('Segment not found.', $this->extractFirstTextContent($result));
        });
    }

    public function testRejectsInvalidIdSiteAtSchemaLevel(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SegmentGet::TOOL_NAME,
            ['idSite' => 0, 'idSegment' => $this->idSegmentAlpha],
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SegmentGet::TOOL_NAME . "':",
            $message->message,
        );
    }

    public function testSchemaDeclaresExactlyOneSelectorWithoutTopLevelCombinators(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-segment-get-schema');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        $segmentGetTool = null;
        foreach ($result->tools as $tool) {
            if ($tool->name === SegmentGet::TOOL_NAME) {
                $segmentGetTool = $tool;
                break;
            }
        }

        self::assertNotNull($segmentGetTool);
        /** @var array<string, mixed> $inputSchema */
        $inputSchema = $segmentGetTool->inputSchema;
        self::assertArrayNotHasKey('oneOf', $inputSchema);
        self::assertArrayNotHasKey('allOf', $inputSchema);
        self::assertArrayNotHasKey('anyOf', $inputSchema);
        self::assertSame(2, $inputSchema['minProperties'] ?? null);
        self::assertSame(2, $inputSchema['maxProperties'] ?? null);

        $properties = $inputSchema['properties'] ?? null;
        self::assertIsArray($properties);
        self::assertArrayHasKey('idSegment', $properties);
        self::assertArrayHasKey('name', $properties);
        self::assertArrayHasKey('definition', $properties);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function callTool(array $arguments): CallToolResult
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SegmentGet::TOOL_NAME,
            $arguments,
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        return McpTestHelper::parseCallTool($message);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function assertInvalidSchemaArguments(array $arguments): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest(
            SegmentGet::TOOL_NAME,
            $arguments,
            __METHOD__,
        );

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);
        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertStringContainsString(
            "Invalid parameters for tool '" . SegmentGet::TOOL_NAME . "':",
            $message->message,
        );
    }

    private function extractFirstTextContent(CallToolResult $result): string
    {
        $content = $result->content[0] ?? null;
        self::assertInstanceOf(TextContent::class, $content);
        self::assertIsString($content->text);

        return $content->text;
    }
}
