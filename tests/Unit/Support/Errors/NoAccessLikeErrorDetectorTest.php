<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Errors;

use PHPUnit\Framework\TestCase;
use Piwik\NoAccessException;
use Piwik\Plugins\McpServer\Support\Errors\AccessDeniedLikeException;
use Piwik\Plugins\McpServer\Support\Errors\NoAccessLikeErrorDetector;

/**
 * @group McpServer
 * @group Plugins
 */
class NoAccessLikeErrorDetectorTest extends TestCase
{
    public function testDetectsAccessDeniedLikeException(): void
    {
        self::assertTrue(NoAccessLikeErrorDetector::isDetected(new AccessDeniedLikeException('no access')));
    }

    public function testDetectsNoAccessException(): void
    {
        self::assertTrue(NoAccessLikeErrorDetector::isDetected(new NoAccessException('denied')));
    }

    public function testDetectsNoAccessLikeMessageCaseInsensitively(): void
    {
        self::assertTrue(
            NoAccessLikeErrorDetector::isDetected(new \RuntimeException('No access to this resource.')),
        );
        self::assertTrue(
            NoAccessLikeErrorDetector::isDetected(new \RuntimeException('CheckUserHasViewAccess failed')),
        );
        self::assertTrue(
            NoAccessLikeErrorDetector::isDetected(new \RuntimeException('Missing VIEW ACCESS permission')),
        );
    }

    public function testDetectsAccessDenialWording(): void
    {
        self::assertTrue(
            NoAccessLikeErrorDetector::isDetected(new \RuntimeException('Access denied for user anonymous.')),
        );
        self::assertTrue(
            NoAccessLikeErrorDetector::isDetected(new \RuntimeException('Permission denied.')),
        );
        self::assertTrue(
            NoAccessLikeErrorDetector::isDetected(
                new \RuntimeException('You are not authorized to perform this action.'),
            ),
        );
        self::assertTrue(
            NoAccessLikeErrorDetector::isDetected(new \RuntimeException('Unauthorized')),
        );
    }

    public function testRejectsEmptyOrUnrelatedMessage(): void
    {
        self::assertFalse(NoAccessLikeErrorDetector::isDetected(new \RuntimeException('')));
        self::assertFalse(NoAccessLikeErrorDetector::isDetected(new \RuntimeException('timeout')));
    }

    public function testRejectsUnauthorizedWordInValidationProse(): void
    {
        self::assertFalse(
            NoAccessLikeErrorDetector::isDetected(new \RuntimeException('unauthorized character in input')),
        );
        self::assertFalse(
            NoAccessLikeErrorDetector::isDetected(
                new \RuntimeException('Please remove unauthorized values from the list.'),
            ),
        );
    }
}
