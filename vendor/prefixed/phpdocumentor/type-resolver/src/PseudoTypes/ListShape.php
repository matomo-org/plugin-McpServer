<?php

declare (strict_types=1);
namespace Matomo\Dependencies\McpServer\phpDocumentor\Reflection\PseudoTypes;

use function implode;
/** @psalm-immutable */
final class ListShape extends ArrayShape
{
    public function __toString() : string
    {
        return 'list{' . implode(', ', $this->getItems()) . '}';
    }
}
