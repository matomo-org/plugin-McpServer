<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\phpDocumentor\Reflection;

use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\Tag;
// phpcs:ignore SlevomatCodingStandard.Classes.SuperfluousInterfaceNaming.SuperfluousSuffix
interface DocBlockFactoryInterface
{
    /**
     * Factory method for easy instantiation.
     *
     * @param array<string, class-string<Tag>> $additionalTags
     */
    public static function createInstance(array $additionalTags = []) : self;
    /**
     * @param string|object $docblock
     */
    public function create($docblock, ?Types\Context $context = null, ?Location $location = null) : DocBlock;
}
