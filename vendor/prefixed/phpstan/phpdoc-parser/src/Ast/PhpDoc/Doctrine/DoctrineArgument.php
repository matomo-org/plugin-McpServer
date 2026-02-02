<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\PhpDoc\Doctrine;

use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Node;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\NodeAttributes;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
/**
 * @phpstan-type ValueType = DoctrineAnnotation|IdentifierTypeNode|DoctrineArray|ConstExprNode
 */
class DoctrineArgument implements Node
{
    use NodeAttributes;
    public ?IdentifierTypeNode $key = null;
    /** @var ValueType */
    public $value;
    /**
     * @param ValueType $value
     */
    public function __construct(?IdentifierTypeNode $key, $value)
    {
        $this->key = $key;
        $this->value = $value;
    }
    public function __toString() : string
    {
        if ($this->key === null) {
            return (string) $this->value;
        }
        return $this->key . '=' . $this->value;
    }
    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties) : self
    {
        $instance = new self($properties['key'], $properties['value']);
        if (isset($properties['attributes'])) {
            foreach ($properties['attributes'] as $key => $value) {
                $instance->setAttribute($key, $value);
            }
        }
        return $instance;
    }
}
