<?php

namespace Utopia\Agents\Exception;

use Utopia\Agents\Exception;

/**
 * The provider rejected or failed a request.
 */
class Api extends Exception
{
    /**
     * @param  string  $type  Provider error type or code, such as "overloaded_error"
     * @param  float|null  $retryAfter  Seconds the provider asked to wait before retrying
     */
    public function __construct(
        string $message,
        public readonly string $provider = '',
        public readonly int $status = 0,
        public readonly string $type = '',
        public readonly bool $retryable = false,
        public readonly ?float $retryAfter = null,
        public readonly string $body = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }
}
