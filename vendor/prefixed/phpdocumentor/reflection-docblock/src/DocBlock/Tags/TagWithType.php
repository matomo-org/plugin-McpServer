<?php

declare (strict_types=1);
/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link      http://phpdoc.org
 */
namespace Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\Tags;

use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\Tag;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Exception\CannotCreateTag;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Type;
abstract class TagWithType extends BaseTag
{
    /** @var ?Type */
    protected ?Type $type = null;
    /**
     * Returns the type section of the variable.
     */
    public function getType() : ?Type
    {
        return $this->type;
    }
    public static final function create(string $body) : Tag
    {
        throw new CannotCreateTag('Typed tag cannot be created');
    }
    public function __toString() : string
    {
        if ($this->description) {
            $description = $this->description->render();
        } else {
            $description = '';
        }
        $type = (string) $this->type;
        return $type . ($description !== '' ? ($type !== '' ? ' ' : '') . $description : '');
    }
}
