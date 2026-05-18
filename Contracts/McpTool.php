<?php

/**
 * Matomo - free/libre analytics platform
 *
 * @link    https://matomo.org
 * @license https://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 */

declare(strict_types=1);

namespace Piwik\Plugins\McpServer\Contracts;

use Matomo\Dependencies\McpServer\Mcp\Schema\Icon;
use Matomo\Dependencies\McpServer\Mcp\Schema\ToolAnnotations;

/**
 * Base class for MCP tools registered by the McpServer plugin.
 *
 * Each subclass represents exactly one MCP tool and MUST define a public
 * execute(...) method whose typed parameters the SDK uses to bind incoming
 * JSON-RPC arguments. The method name is intentionally fixed (no handlerMethod
 * property) because there is no remaining value in per-tool configurability.
 */
abstract class McpTool
{
    protected string $name;
    protected string $description;
    protected ToolAnnotations $annotations;
    protected ?string $title = null;

    /** @var array<string, mixed> */
    protected array $inputSchema;

    /** @var array<string, mixed>|null */
    protected ?array $outputSchema = null;

    /** @var list<Icon>|null */
    protected ?array $icons = null;

    /** @var array<string, mixed>|null */
    protected ?array $meta = null;

    public function __construct()
    {
        // Placeholder defaults so static analysers can prove the typed properties are
        // initialised. Subclasses MUST overwrite the required ones inside init().
        $this->name = '';
        $this->description = '';
        $this->annotations = new ToolAnnotations();
        $this->inputSchema = [];

        $this->init();

        if ($this->name === '') {
            throw new \LogicException(sprintf(
                '%s::init() must set $this->name to a non-empty tool name.',
                static::class,
            ));
        }
        if ($this->inputSchema === []) {
            throw new \LogicException(sprintf(
                '%s::init() must set $this->inputSchema.',
                static::class,
            ));
        }

        // PHP cannot express "every subclass declares execute(...)" as an abstract
        // method because the SDK binds JSON-RPC arguments to typed parameters whose
        // signatures vary per tool — declaring abstract execute() with any concrete
        // signature would violate LSP for those overrides. A runtime check at boot
        // is the practical safety net; it fires the first time any tool is built.
        if (!method_exists($this, 'execute')) {
            throw new \LogicException(sprintf(
                '%s must define a public execute() method.',
                static::class,
            ));
        }
    }

    abstract protected function init(): void;

    public function shouldRegister(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getAnnotations(): ToolAnnotations
    {
        return $this->annotations;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array
    {
        return $this->inputSchema;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getOutputSchema(): ?array
    {
        return $this->outputSchema;
    }

    /**
     * @return list<Icon>|null
     */
    public function getIcons(): ?array
    {
        return $this->icons;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getMeta(): ?array
    {
        return $this->meta;
    }
}
