<?php

namespace Utopia\Image\Exception;

/**
 * Data that is not an image of a supported format, an output type that is not
 * one, or a format this system has no codec for.
 */
final class UnsupportedFormatException extends ImageException
{
    public function __construct(string $message = 'Unsupported image format', ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
