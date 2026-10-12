<?php

namespace Tests\Compat\Nats;

use Utopia\NATS\Exception\AuthenticationException;
use Utopia\NATS\Exception\ConnectionException;
use Utopia\NATS\Exception\NatsException;
use Utopia\NATS\Exception\ProtocolException;
use Utopia\NATS\Exception\TimeoutException;
use Utopia\NATS\Services\ServiceException;

/**
 * Exceptions a scripted fixture raises: {"class": short name, "message"}.
 * The twin of `scripted_error` in crates/tools/compat/src/libs/nats.rs.
 */
final class Errors
{
    /** @param array{class: string, message: string, code?: string} $spec */
    public static function make(array $spec): \Throwable
    {
        $message = (string) $spec['message'];

        return match ($spec['class']) {
            'NatsException' => new NatsException($message),
            'ConnectionException' => new ConnectionException($message),
            'TimeoutException' => new TimeoutException($message),
            'ProtocolException' => new ProtocolException($message),
            'AuthenticationException' => new AuthenticationException($message),
            'ServiceException' => new ServiceException((string) ($spec['code'] ?? '500'), $message),
            'RuntimeException' => new \RuntimeException($message),
            default => new \Exception($message),
        };
    }
}
