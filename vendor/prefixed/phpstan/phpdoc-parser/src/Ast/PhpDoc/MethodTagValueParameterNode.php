<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\PhpDoc;

use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Node;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\NodeAttributes;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Type\TypeNode;
class MethodTagValueParameterNode implements Node
{
    use NodeAttributes;
    public ?TypeNode $type = null;
    public bool $isReference;
    public bool $isVariadic;
    public string $parameterName;
    public ?ConstExprNode $defaultValue = null;
    public function __construct(?TypeNode $type, bool $isReference, bool $isVariadic, string $parameterName, ?ConstExprNode $defaultValue)
    {
        $this->type = $type;
        $this->isReference = $isReference;
        $this->isVariadic = $isVariadic;
        $this->parameterName = $parameterName;
        $this->defaultValue = $defaultValue;
    }
    public function __toString() : string
    {
        $type = $this->type !== null ? "{$this->type} " : '';
        $isReference = $this->isReference ? '&' : '';
        $isVariadic = $this->isVariadic ? '...' : '';
        $default = $this->defaultValue !== null ? " = {$this->defaultValue}" : '';
        return "{$type}{$isReference}{$isVariadic}{$this->parameterName}{$default}";
    }
    /**
     * @param array<string, mixed> $properties
     */
    public static function __set_state(array $properties) : self
    {
        $instance = new self($properties['type'], $properties['isReference'], $properties['isVariadic'], $properties['parameterName'], $properties['defaultValue']);
        if (isset($properties['attributes'])) {
            foreach ($properties['attributes'] as $key => $value) {
                $instance->setAttribute($key, $value);
            }
        }
        return $instance;
    }
}
