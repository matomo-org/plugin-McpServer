<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\tests\Unit\Contracts;

use PHPUnit\Framework\TestCase;
use Piwik\Plugins\McpServer\Contracts\McpTool;
use Piwik\Plugins\McpServer\Contracts\McpToolAnnotations;

/**
 * @group McpServer
 * @group Plugins
 */
class McpToolTest extends TestCase
{
    public function testConstructorRejectsSubclassThatDoesNotSetName(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('init() must set $this->name to a non-empty tool name.');

        // Anonymous subclass populates inputSchema but leaves $this->name at the
        // placeholder default, so the constructor must fire the name-validation
        // branch. execute() is declared so the failure cannot come from the
        // unrelated execute()-existence check.
        $tool = new class () extends McpTool {
            protected function init(): void
            {
                $this->inputSchema = ['type' => 'object'];
            }

            public function execute(): void
            {
            }
        };
        self::fail(sprintf('Constructor unexpectedly returned an instance of %s.', $tool::class));
    }

    public function testConstructorRejectsSubclassThatDoesNotSetInputSchema(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('init() must set $this->inputSchema.');

        // Anonymous subclass populates name but leaves $this->inputSchema at the
        // placeholder default, so the constructor must fire the inputSchema
        // branch. execute() is declared for the same reason as above.
        $tool = new class () extends McpTool {
            protected function init(): void
            {
                $this->name = 'test_tool_without_input_schema';
            }

            public function execute(): void
            {
            }
        };
        self::fail(sprintf('Constructor unexpectedly returned an instance of %s.', $tool::class));
    }

    public function testConstructorRejectsSubclassWithoutExecuteMethod(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must define a public execute() method.');

        // McpTool intentionally does not declare execute() abstract; the runtime
        // check inside the constructor is the safety net for that case. init()
        // populates the earlier required fields so the constructor reaches the
        // execute()-existence check instead of failing on name/inputSchema first.
        $tool = new class () extends McpTool {
            protected function init(): void
            {
                $this->name = 'test_tool_without_execute';
                $this->inputSchema = ['type' => 'object'];
            }
        };
        self::fail(sprintf('Constructor unexpectedly returned an instance of %s.', $tool::class));
    }

    public function testConstructorAcceptsSubclassWithPartiallyDeclaredAnnotationHints(): void
    {
        // The base class deliberately does not require every behavioural hint
        // to be non-null: McpToolAnnotations declares them as ?bool, the MCP
        // spec leaves "unknown" interpretation to clients, and the in-process
        // catalogue passes nulls straight through. This test pins that lenient
        // contract so a future re-tightening cannot land silently.
        $tool = new class () extends McpTool {
            protected function init(): void
            {
                $this->name = 'test_tool_with_partial_annotations';
                $this->inputSchema = ['type' => 'object'];
                $this->annotations = new McpToolAnnotations(
                    readOnlyHint: true,
                    destructiveHint: null,
                    idempotentHint: null,
                    openWorldHint: false,
                );
            }

            public function execute(): void
            {
            }
        };

        self::assertSame('test_tool_with_partial_annotations', $tool->getName());
        self::assertTrue($tool->getAnnotations()->readOnlyHint);
        self::assertNull($tool->getAnnotations()->destructiveHint);
        self::assertNull($tool->getAnnotations()->idempotentHint);
        self::assertFalse($tool->getAnnotations()->openWorldHint);
    }
}
