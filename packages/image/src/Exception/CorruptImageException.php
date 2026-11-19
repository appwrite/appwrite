<?php

namespace Utopia\Image\Exception;

/**
 * Data of a supported format that cannot be decoded.
 */
final class CorruptImageException extends ImageException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Corrupt or truncated image', 0, $previous);
    }
}
