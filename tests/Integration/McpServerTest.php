<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Integration;

use Matomo\Dependencies\McpServer\Mcp\Schema\JsonRpc\Error as JsonRpcError;
use Piwik\Access;
use Piwik\Container\StaticContainer;
use Piwik\Plugin\Manager;
use Piwik\Plugins\McpServer\Support\Access\McpAccessLevel;
use Piwik\Plugins\McpServer\Support\Access\RawApiAccessMode;
use Piwik\Plugins\McpServer\SystemSettings;
use Piwik\Plugins\McpServer\tests\Framework\McpAuthTestHelper;
use Piwik\Plugins\McpServer\tests\Framework\McpTestHelper;
use Piwik\Tests\Framework\TestCase\IntegrationTestCase;

/**
 * @group McpServer
 * @group Plugins
 */
class McpServerTest extends IntegrationTestCase
{
    public function testInitialize(): void
    {
        $server = McpTestHelper::buildServer();
        $payload = McpTestHelper::makeInitializeRequest('init-1');

        $response = McpTestHelper::postJson($server, $payload);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseInitialize($message);
        $pluginVersion = (string) Manager::getInstance()->getVersion('McpServer');

        self::assertSame('init-1', $message->id);
        self::assertNotSame('', $result->protocolVersion);
        self::assertNotSame('', $result->serverInfo->name);
        self::assertSame($pluginVersion, $result->serverInfo->version);
    }

    public function testToolsList(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-1');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeResponse($response);
        $result = McpTestHelper::parseListTools($message);

        self::assertSame('list-1', $message->id);
        self::assertNotEmpty($result->tools);
    }

    public function testMissingSessionId(): void
    {
        $server = McpTestHelper::buildServer();
        $payload = McpTestHelper::makeListToolsRequest('list-1');

        $response = McpTestHelper::postJson($server, $payload);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_REQUEST, $message->code);
        self::assertSame('A valid session id is REQUIRED for non-initialize requests.', $message->message);
    }

    public function testUnknownTool(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeCallToolRequest('missing_tool', [], 'missing-1');

        $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_PARAMS, $message->code);
        self::assertSame('Tool not found: "missing_tool".', $message->message);
    }

    public function testInvalidJson(): void
    {
        $server = McpTestHelper::buildServer();

        $response = McpTestHelper::postJson($server, '{invalid-json');
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::PARSE_ERROR, $message->code);
        self::assertSame('Syntax error', $message->message);
    }

    public function testInitializeBatch(): void
    {
        $server = McpTestHelper::buildServer();

        $initialize = \json_decode(McpTestHelper::makeInitializeRequest('init-1'), true, 512, \JSON_THROW_ON_ERROR);
        $ping = \json_decode(McpTestHelper::makePingRequest('ping-1'), true, 512, \JSON_THROW_ON_ERROR);

        $response = McpTestHelper::postJson($server, \json_encode([$initialize, $ping], \JSON_THROW_ON_ERROR));
        $message = McpTestHelper::decodeError($response);

        self::assertSame(JsonRpcError::INVALID_REQUEST, $message->code);
        self::assertSame('The "initialize" request MUST NOT be part of a batch.', $message->message);
    }

    public function testAnonymousCannotImpersonateAuthenticatedSession(): void
    {
        $server = McpTestHelper::buildServer();
        $sessionId = McpTestHelper::initializeSession($server);
        $payload = McpTestHelper::makeListToolsRequest('list-1');
        $originalTokenAuth = McpAuthTestHelper::captureCurrentTokenAuth();

        try {
            McpAuthTestHelper::switchToAnonymous();

            $response = McpTestHelper::postJson($server, $payload, ['Mcp-Session-Id' => $sessionId]);
            $actualError = McpTestHelper::decodeError($response);
            self::assertSame(JsonRpcError::INVALID_REQUEST, $actualError->code);
            self::assertSame('Session not found or has expired.', $actualError->message);
        } finally {
            McpAuthTestHelper::restoreAuth($originalTokenAuth);
        }
    }

    public function testContainerSystemSettingCanBeToggled(): void
    {
        $systemSettings = StaticContainer::get(SystemSettings::class);
        self::assertInstanceOf(SystemSettings::class, $systemSettings);
        $originalEnableMcpValue = (bool) $systemSettings->enableMcp->getValue();
        $originalMaximumAllowedMcpAccessLevel = $systemSettings->getMaximumAllowedMcpAccessLevel();
        $originalRawApiAccessMode = $systemSettings->getRawApiAccessMode();

        Access::getInstance()->setSuperUserAccess(true);

        try {
            $systemSettings->enableMcp->setValue(false);
            self::assertFalse($systemSettings->isMcpEnabled());

            $systemSettings->enableMcp->setValue(true);
            self::assertTrue($systemSettings->isMcpEnabled());

            $systemSettings->maximumMcpAccessLevel->setValue(McpAccessLevel::VIEW);
            self::assertSame(McpAccessLevel::VIEW, $systemSettings->getMaximumAllowedMcpAccessLevel());

            $systemSettings->maximumMcpAccessLevel->setValue(McpAccessLevel::WRITE);
            self::assertSame(McpAccessLevel::WRITE, $systemSettings->getMaximumAllowedMcpAccessLevel());

            $systemSettings->maximumMcpAccessLevel->setValue(McpAccessLevel::ADMIN);
            self::assertSame(McpAccessLevel::ADMIN, $systemSettings->getMaximumAllowedMcpAccessLevel());

            $this->applyRawApiAccessMode($systemSettings, RawApiAccessMode::READ);
            self::assertSame('read', $systemSettings->getRawApiAccessMode());

            $this->applyRawApiAccessMode($systemSettings, RawApiAccessMode::CREATE);
            self::assertSame('create', $systemSettings->getRawApiAccessMode());

            $this->applyRawApiAccessMode($systemSettings, RawApiAccessMode::UPDATE);
            self::assertSame('update', $systemSettings->getRawApiAccessMode());

            $this->applyRawApiAccessMode($systemSettings, RawApiAccessMode::DELETE);
            self::assertSame('delete', $systemSettings->getRawApiAccessMode());

            $this->applyRawApiAccessMode($systemSettings, RawApiAccessMode::FULL);
            self::assertSame('full', $systemSettings->getRawApiAccessMode());
        } finally {
            $systemSettings->enableMcp->setValue($originalEnableMcpValue);
            $systemSettings->maximumMcpAccessLevel->setValue($originalMaximumAllowedMcpAccessLevel);
            $this->applyRawApiAccessMode($systemSettings, $originalRawApiAccessMode);
            Access::getInstance()->setSuperUserAccess(false);
        }
    }

    private function applyRawApiAccessMode(SystemSettings $systemSettings, string $mode): void
    {
        $normalizedMode = RawApiAccessMode::normalize($mode);

        $systemSettings->rawApiAccessRead->setValue(
            RawApiAccessMode::allowsCategory($normalizedMode, RawApiAccessMode::READ),
        );
        $systemSettings->rawApiAccessCreate->setValue(
            RawApiAccessMode::allowsCategory($normalizedMode, RawApiAccessMode::CREATE),
        );
        $systemSettings->rawApiAccessUpdate->setValue(
            RawApiAccessMode::allowsCategory($normalizedMode, RawApiAccessMode::UPDATE),
        );
        $systemSettings->rawApiAccessDelete->setValue(
            RawApiAccessMode::allowsCategory($normalizedMode, RawApiAccessMode::DELETE),
        );
        $systemSettings->rawApiAccessScope->setValue(match ($normalizedMode) {
            RawApiAccessMode::FULL => 'full',
            RawApiAccessMode::NONE => 'none',
            default => 'partial',
        });
    }
}
