<?php

namespace Executor\Exception;

use Executor\Exception;
use Throwable;

class Timeout extends Exception
{
    public function __construct(
        string $message,
        private readonly int $timeoutSeconds,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    public function getTimeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }
}
