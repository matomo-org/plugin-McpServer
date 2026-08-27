<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Support\Normalization;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Support\Normalization\ArgumentIssueException;
use Piwik\Plugins\McpServer\Support\Normalization\BoundedJsonObjectDecoder;

/**
 * @group McpServer
 * @group Plugins
 */
class BoundedJsonObjectDecoderTest extends TestCase
{
    public function testDecodesJsonObject(): void
    {
        self::assertSame(
            ['expanded' => true, 'filter_sort_column' => 'nb_visits'],
            BoundedJsonObjectDecoder::decode('{"expanded":true,"filter_sort_column":"nb_visits"}', '/apiParameters'),
        );
    }

    public function testAcceptsSurroundingWhitespace(): void
    {
        self::assertSame(['flat' => true], BoundedJsonObjectDecoder::decode("  {\"flat\":true}\n", '/apiParameters'));
    }

    public function testDecodesEmptyObject(): void
    {
        self::assertSame([], BoundedJsonObjectDecoder::decode('{}', '/apiParameters'));
    }

    public function testDoesNotRecursivelyDecodeNestedStrings(): void
    {
        self::assertSame(
            ['label' => '{"nested":true}'],
            BoundedJsonObjectDecoder::decode('{"label":"{\"nested\":true}"}', '/apiParameters'),
        );
    }

    /**
     * @dataProvider provideRejectedInput
     */
    public function testRejectsInput(string $raw): void
    {
        $this->expectException(ArgumentIssueException::class);

        BoundedJsonObjectDecoder::decode($raw, '/apiParameters');
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideRejectedInput(): iterable
    {
        yield 'array' => ['[1,2,3]'];
        yield 'scalar string' => ['"expanded"'];
        yield 'scalar number' => ['42'];
        yield 'null' => ['null'];
        yield 'empty string' => [''];
        yield 'trailing content' => ['{"a":1} trailing'];
        yield 'invalid json' => ['{"a":}'];
        yield 'invalid utf8' => ["{\"a\":\"\xB1\x31\"}"];
        // JSON whitespace is space, tab, LF and CR only. A leading NUL or vertical tab is a
        // control character json_decode() refuses, and reaches a parameter string as a legal
        // outer-JSON escape, so it must not be trimmed away into an accepted object.
        yield 'NUL before the object' => ["\0{\"a\":1}"];
        yield 'vertical tab before the object' => ["\x0B{\"a\":1}"];
        // A trailing one reaches the decode, since the value still opens as an object, and is
        // reported there rather than stripped.
        yield 'NUL after the object' => ["{\"a\":1}\0"];
        // A bare-number key loses the key/index distinction once decoded. The all-numeric case is
        // caught as a list; the mixed case is the only one that reaches the key check.
        yield 'bare-number key' => ['{"0":1}'];
        yield 'mixed bare-number and string keys' => ['{"1":1,"a":2}'];

        yield 'excessive properties' => [self::wideObject(BoundedJsonObjectDecoder::MAX_PROPERTIES + 1)];
    }

    public function testAcceptsAnObjectAtThePropertyBound(): void
    {
        self::assertNotSame(
            [],
            BoundedJsonObjectDecoder::decode(self::wideObject(BoundedJsonObjectDecoder::MAX_PROPERTIES), '/x'),
        );
    }

    /**
     * MAX_DEPTH counts container levels including the outer object, which is what the error
     * message states, and sits one level from json_decode()'s own convention.
     */
    public function testDepthBoundCountsContainerLevelsIncludingTheOuterObject(): void
    {
        BoundedJsonObjectDecoder::decode(self::nestedObject(BoundedJsonObjectDecoder::MAX_DEPTH), '/x');

        $this->expectException(ArgumentIssueException::class);
        BoundedJsonObjectDecoder::decode(self::nestedObject(BoundedJsonObjectDecoder::MAX_DEPTH + 1), '/x');
    }

    /**
     * Lists count towards the depth bound as well; only the property bound treats them
     * differently.
     */
    public function testDepthBoundCountsListLevelsToo(): void
    {
        $withinBound = '{"a":' . self::nestedList(BoundedJsonObjectDecoder::MAX_DEPTH - 1) . '}';
        self::assertNotSame([], BoundedJsonObjectDecoder::decode($withinBound, '/x'));

        $this->expectException(ArgumentIssueException::class);
        BoundedJsonObjectDecoder::decode(
            '{"a":' . self::nestedList(BoundedJsonObjectDecoder::MAX_DEPTH) . '}',
            '/x',
        );
    }

    public function testRejectsNestedObjectExceedingThePropertyBound(): void
    {
        $this->expectException(ArgumentIssueException::class);

        BoundedJsonObjectDecoder::decode(
            '{"nested":' . self::wideObject(BoundedJsonObjectDecoder::MAX_PROPERTIES + 1) . '}',
            '/apiParameters',
        );
    }

    /**
     * A parameter map may carry a list longer than the property bound, such as every site ID on a
     * large instance, so list entries are not counted as properties.
     */
    public function testAcceptsAListLongerThanThePropertyBound(): void
    {
        $decoded = BoundedJsonObjectDecoder::decode(
            '{"idSites":' . json_encode(range(1, BoundedJsonObjectDecoder::MAX_PROPERTIES + 100)) . '}',
            '/parameters',
        );

        $idSites = $decoded['idSites'] ?? null;
        self::assertIsArray($idSites);
        self::assertCount(BoundedJsonObjectDecoder::MAX_PROPERTIES + 100, $idSites);
    }

    public function testRejectsAnObjectNestedInsideAListExceedingThePropertyBound(): void
    {
        $this->expectException(ArgumentIssueException::class);

        BoundedJsonObjectDecoder::decode(
            '{"filters":[' . self::wideObject(BoundedJsonObjectDecoder::MAX_PROPERTIES + 1) . ']}',
            '/parameters',
        );
    }

    public function testErrorMessageDoesNotEchoTheOriginalString(): void
    {
        try {
            BoundedJsonObjectDecoder::decode('{"token_auth":"s3cr3t-value","broken"', '/parameters');
        } catch (ArgumentIssueException $e) {
            self::assertStringNotContainsString('s3cr3t-value', $e->getMessage());
            self::assertStringNotContainsString('token_auth', $e->getMessage());
            self::assertSame(['/parameters'], $e->paths);

            return;
        }

        self::fail('Expected an ArgumentIssueException.');
    }

    /**
     * @dataProvider provideJsonObjectLookalikes
     */
    public function testLooksLikeJsonObject(mixed $value, bool $expected): void
    {
        self::assertSame($expected, BoundedJsonObjectDecoder::looksLikeJsonObject($value));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: bool}>
     */
    public static function provideJsonObjectLookalikes(): iterable
    {
        yield 'object' => ['{"a":1}', true];
        yield 'object behind whitespace' => ["  \n\t{\"a\":1}", true];
        // Not JSON whitespace, so the value is never taken for an object string and keeps the
        // schema's type rejection instead of becoming a decode failure.
        yield 'object behind a NUL' => ["\0{\"a\":1}", false];
        yield 'object behind a vertical tab' => ["\x0B{\"a\":1}", false];
        yield 'truncated object still looks like one' => ['{"a"', true];
        yield 'array' => ['[1,2]', false];
        yield 'quoted scalar' => ['"{}"', false];
        yield 'bare word' => ['expanded', false];
        yield 'number' => ['1', false];
        yield 'empty string' => ['', false];
        yield 'real array value' => [[], false];
        yield 'integer value' => [1, false];
        yield 'null value' => [null, false];
    }

    private static function nestedObject(int $levels): string
    {
        $json = '1';
        for ($i = 0; $i < $levels; $i++) {
            $json = '{"a":' . $json . '}';
        }

        return $json;
    }

    private static function nestedList(int $levels): string
    {
        $json = '1';
        for ($i = 0; $i < $levels; $i++) {
            $json = '[' . $json . ']';
        }

        return $json;
    }

    private static function wideObject(int $properties): string
    {
        $pairs = [];
        for ($i = 0; $i < $properties; $i++) {
            $pairs[] = '"k' . $i . '":' . $i;
        }

        return '{' . implode(',', $pairs) . '}';
    }
}
