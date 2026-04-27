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
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\Boolean;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\Compound;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\Float_;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\Integer;
use Matomo\Dependencies\McpServer\phpDocumentor\Reflection\Types\String_;
/**
 * Value Object representing the 'scalar' pseudo-type, which is either a string, integer, float or boolean.
 *
 * @psalm-immutable
 */
final class Scalar implements PseudoType
{
    public function underlyingType() : Type
    {
        return new Compound([new String_(), new Integer(), new Float_(), new Boolean()]);
    }
    /**
     * Returns a rendered output of the Type as it would be used in a DocBlock.
     */
    public function __toString() : string
    {
        return 'scalar';
    }
}
