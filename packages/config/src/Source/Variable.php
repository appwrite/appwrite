<?php

declare(strict_types=1);

namespace Utopia\Config\Source;

use Utopia\Config\Source;

class Variable extends Source
{
    public function __construct(mixed $contents)
    {
        $this->contents = $contents;
    }
}
