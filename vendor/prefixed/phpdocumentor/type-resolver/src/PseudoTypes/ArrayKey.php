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
namespace Matomo\Dependencies\McpServer\phpDocumentor\Reflection\PseudoTypes;

use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\PseudoType;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Type;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\AggregatedType;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\Compound;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\Integer;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\String_;
/**
 * Value Object representing the type `array-key`.
 *
 * @psalm-immutable
 */
class ArrayKey extends AggregatedType implements PseudoType
{
    public function __construct()
    {
        parent::__construct([new String_(), new Integer()], '|');
    }
    public function underlyingType() : Type
    {
        return new Compound([new String_(), new Integer()]);
    }
    public function __toString() : string
    {
        return 'array-key';
    }
}
