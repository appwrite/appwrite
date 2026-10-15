<?php

namespace Utopia\Query;

use Throwable;

class Exception extends \Exception
{
    public function __construct(string $message, int|string $code = 0, ?Throwable $previous = null)
    {
        if (\is_string($code)) {
            if (\is_numeric($code)) {
                $code = (int) $code;
            } else {
                $code = 0;
            }
        }

        parent::__construct($message, $code, $previous);
    }
}
