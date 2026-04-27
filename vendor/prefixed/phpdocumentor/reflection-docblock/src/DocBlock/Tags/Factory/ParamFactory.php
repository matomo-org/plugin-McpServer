<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\Tags\Factory;

use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\Tag;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\Tags\InvalidTag;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\Tags\Param;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Exception\ParserException;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\TypeResolver;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\Context;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\PhpDoc\InvalidTagValueNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\PhpDoc\ParamTagValueNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\PhpDoc\TypelessParamTagValueNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use Matomo\Dependencies\McpServer\PHPStan\PhpDocParser\Ast\Type\OffsetAccessTypeNode;
use Matomo\Dependencies\McpServer\Webmozart\Assert\Assert;
use function is_string;
use function trim;
/**
 * @internal This class is not part of the BC promise of this library.
 */
final class ParamFactory implements PHPStanFactory
{
    private DescriptionFactory $descriptionFactory;
    private TypeResolver $typeResolver;
    public function __construct(TypeResolver $typeResolver, DescriptionFactory $descriptionFactory)
    {
        $this->descriptionFactory = $descriptionFactory;
        $this->typeResolver = $typeResolver;
    }
    public function create(PhpDocTagNode $node, Context $context) : Tag
    {
        $tagValue = $node->value;
        if ($tagValue instanceof InvalidTagValueNode) {
            return InvalidTag::create($tagValue->value, 'param')->withError(ParserException::from($tagValue->exception));
        }
        Assert::isInstanceOfAny($tagValue, [ParamTagValueNode::class, TypelessParamTagValueNode::class]);
        if (($tagValue->type ?? null) instanceof OffsetAccessTypeNode) {
            return InvalidTag::create((string) $tagValue, 'param');
        }
        $description = $tagValue->getAttribute('description');
        if (is_string($description) === \false) {
            $description = $tagValue->description;
        }
        return new Param(trim($tagValue->parameterName, '$'), $this->typeResolver->createType($tagValue->type ?? new IdentifierTypeNode('mixed'), $context), $tagValue->isVariadic, $this->descriptionFactory->create($description, $context), $tagValue->isReference);
    }
    public function supports(PhpDocTagNode $node, Context $context) : bool
    {
        return $node->value instanceof ParamTagValueNode || $node->value instanceof TypelessParamTagValueNode || $node->name === '@param';
    }
}
