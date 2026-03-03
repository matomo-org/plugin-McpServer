<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Errors;

use Matomo\Dependencies\McpServer\Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Piwik\Access;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Support\Access\ViewAccessFallback;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\ToolErrorMapper;

/**
 * @group McpServer
 * @group Plugins
 */
class ToolErrorMapperTest extends TestCase
{
    public function tearDown(): void
    {
        Access::getInstance()->setSuperUserAccess(false);
        parent::tearDown();
    }

    public function testShouldReturnEmptyListForReturnsTrueForNoAccessException(): void
    {
        self::assertTrue(
            ToolErrorMapper::shouldReturnEmptyListFor(new NoAccessException('no access'))
        );
    }

    public function testShouldReturnEmptyListForReturnsTrueForAccessDeniedLikeException(): void
    {
        self::assertTrue(
            ToolErrorMapper::shouldReturnEmptyListFor(new AccessDeniedLikeException('no access'))
        );
    }

    public function testShouldReturnEmptyListForUsesNoAccessLikeCallbackWhenFallbackEnabled(): void
    {
        Access::getInstance()->setSuperUserAccess(false);

        $expected = ViewAccessFallback::shouldReturnEmptyOnNoAccessFallback();
        $actual = ToolErrorMapper::shouldReturnEmptyListFor(
            new \RuntimeException('some failure'),
            static fn(\Throwable $e): bool => true
        );

        self::assertSame($expected, $actual);
    }

    public function testShouldReturnEmptyListForReturnsFalseWhenCallbackFalseAndFallbackDisabled(): void
    {
        $actual = ToolErrorMapper::shouldReturnEmptyListFor(
            new \RuntimeException('some failure'),
            static fn(\Throwable $e): bool => false,
            false
        );

        self::assertFalse($actual);
    }

    public function testThrowDetailFailureMapsNoAccessToNotFoundMessage(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not-found');

        ToolErrorMapper::throwDetailFailure(
            new NoAccessException('no access'),
            'not-found',
            'failed'
        );
    }

    public function testThrowDetailFailureMapsAccessDeniedLikeToNotFoundMessage(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not-found');

        ToolErrorMapper::throwDetailFailure(
            new AccessDeniedLikeException('no access'),
            'not-found',
            'failed'
        );
    }

    public function testThrowDetailFailureMapsCallbackMatchedExceptionToNotFoundMessage(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('not-found');

        ToolErrorMapper::throwDetailFailure(
            new \RuntimeException('some failure'),
            'not-found',
            'failed',
            static fn(\Throwable $e): bool => true
        );
    }

    public function testThrowDetailFailureMapsUnmatchedExceptionToFailedMessage(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('failed');

        ToolErrorMapper::throwDetailFailure(
            new \RuntimeException('some failure'),
            'not-found',
            'failed',
            static fn(\Throwable $e): bool => false
        );
    }
}
