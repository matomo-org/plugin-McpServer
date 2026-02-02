<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\NodeVisitor;

use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\AbstractNodeVisitor;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Attribute;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Node;
final class CloningVisitor extends AbstractNodeVisitor
{
    public function enterNode(Node $originalNode) : Node
    {
        $node = clone $originalNode;
        $node->setAttribute(Attribute::ORIGINAL_NODE, $originalNode);
        return $node;
    }
}
