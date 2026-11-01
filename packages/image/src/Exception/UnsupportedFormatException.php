<?php

namespace Utopia\Image\Exception;

use Exception;

final class UnsupportedFormatException extends Exception
{
    public function __construct()
    {
        parent::__construct('Unsupported image format');
    }
}
