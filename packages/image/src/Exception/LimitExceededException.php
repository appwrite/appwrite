<?php

namespace Utopia\Image\Exception;

/**
 * An image larger than its canvas's dimension or memory limits (Limits).
 */
final class LimitExceededException extends ImageException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct('Image exceeds the size or memory limits', 0, $previous);
    }
}
