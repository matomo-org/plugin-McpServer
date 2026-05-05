<?php

declare (strict_types=1);
/**
 * This file is part of phpDocumentor.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @link http://phpdoc.org
 */
namespace Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\Tags;

use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\Description;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\DocBlock\DescriptionFactory;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\Context as TypeContext;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Utils;
use Matomo\Dependencies\McpServer\Webmozart\Assert\Assert;
/**
 * Reflection class for a {@}link tag in a Docblock.
 */
final class Link extends BaseTag
{
    protected string $name = 'link';
    private string $link;
    /**
     * Initializes a link to a URL.
     */
    public function __construct(string $link, ?Description $description = null)
    {
        $this->link = $link;
        $this->description = $description;
    }
    public static function create(string $body, ?DescriptionFactory $descriptionFactory = null, ?TypeContext $context = null) : self
    {
        Assert::notNull($descriptionFactory);
        $parts = Utils::pregSplit('/\\s+/Su', $body, 2);
        $description = isset($parts[1]) ? $descriptionFactory->create($parts[1], $context) : null;
        return new static($parts[0], $description);
    }
    /**
     * Gets the link
     */
    public function getLink() : string
    {
        return $this->link;
    }
    /**
     * Returns a string representation for this tag.
     */
    public function __toString() : string
    {
        if ($this->description) {
            $description = $this->description->render();
        } else {
            $description = '';
        }
        $link = $this->link;
        return $link . ($description !== '' ? ($link !== '' ? ' ' : '') . $description : '');
    }
}
