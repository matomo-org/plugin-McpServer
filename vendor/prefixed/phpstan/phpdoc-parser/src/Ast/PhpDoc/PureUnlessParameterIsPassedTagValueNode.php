<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\PhpDoc;

use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\NodeAttributes;
use function trim;
class PureUnlessParameterIsPassedTagValueNode implements PhpDocTagValueNode
{
    use NodeAttributes;
    public string $parameterName;
    /** @var string (may be empty) */
    public string $description;
    public function __construct(string $parameterName, string $description)
    {
        $this->parameterName = $parameterName;
        $this->description = $description;
    }
    public function __toString() : string
    {
        return trim("{$this->parameterName} {$this->description}");
    }
}
