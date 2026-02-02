<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\PhpDoc;

use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\NodeAttributes;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Type\TypeNode;
use function trim;
class RequireExtendsTagValueNode implements PhpDocTagValueNode
{
    use NodeAttributes;
    public TypeNode $type;
    /** @var string (may be empty) */
    public string $description;
    public function __construct(TypeNode $type, string $description)
    {
        $this->type = $type;
        $this->description = $description;
    }
    public function __toString() : string
    {
        return trim("{$this->type} {$this->description}");
    }
    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties) : self
    {
        $instance = new self($properties['type'], $properties['description']);
        if (isset($properties['attributes'])) {
            foreach ($properties['attributes'] as $key => $value) {
                $instance->setAttribute($key, $value);
            }
        }
        return $instance;
    }
}
