<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Type;

use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Node;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\NodeAttributes;
use function sprintf;
class ArrayShapeItemNode implements Node
{
    use NodeAttributes;
    /** @var ConstExprIntegerNode|ConstExprStringNode|ConstFetchNode|IdentifierTypeNode|null */
    public $keyName;
    public bool $optional;
    public TypeNode $valueType;
    /**
     * @param ConstExprIntegerNode|ConstExprStringNode|ConstFetchNode|IdentifierTypeNode|null $keyName
     */
    public function __construct($keyName, bool $optional, TypeNode $valueType)
    {
        $this->keyName = $keyName;
        $this->optional = $optional;
        $this->valueType = $valueType;
    }
    public function __toString() : string
    {
        if ($this->keyName !== null) {
            return sprintf('%s%s: %s', (string) $this->keyName, $this->optional ? '?' : '', (string) $this->valueType);
        }
        return (string) $this->valueType;
    }
    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties) : self
    {
        $instance = new self($properties['keyName'], $properties['optional'], $properties['valueType']);
        if (isset($properties['attributes'])) {
            foreach ($properties['attributes'] as $key => $value) {
                $instance->setAttribute($key, $value);
            }
        }
        return $instance;
    }
}
