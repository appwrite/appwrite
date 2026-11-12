<?php

namespace Utopia\Image\Exception;

/**
 * An image larger than the dimension or memory limits (Image::setResourceLimit()).
 */
final class LimitExceededException extends ImageException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Image exceeds the size or memory limits', 0, $previous);
    }
}
