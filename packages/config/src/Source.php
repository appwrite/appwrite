<?php

declare(strict_types=1);

namespace Utopia\Config;

abstract class Source
{
    protected mixed $contents;

    public function getContents(): mixed
    {
        return $this->contents;
    }
}
