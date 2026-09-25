<?php

namespace Utopia\Cache\Adapter\Redis;

/**
 * Internal signal: the server has no cached script for the SHA sent via EVALSHA,
 * so the caller must resend the full body with EVAL (which also re-caches it).
 * Deliberately not a RedisException subclass so the adapters' reconnect/retry
 * paths — which key on RedisException — leave it alone and let leaseRun() catch it.
 */
final class NoScript extends \RuntimeException
{
    /**
     * RESP error code for an EVALSHA cache miss. Redis error replies carry no
     * numeric code (getCode() is 0 on both the raw socket and php-redis), so the
     * code is the reply's leading token — "NOSCRIPT No matching script." — the
     * same across the raw RESP frame and php-redis getLastError().
     */
    private const string CODE = 'NOSCRIPT';

    /**
     * True when a Redis error string's code (its leading token) is NOSCRIPT.
     * Matching the token, not a substring, avoids false positives on echoed key
     * or value text that merely contains the word.
     */
    public static function matches(string $error): bool
    {
        return explode(' ', $error, 2)[0] === self::CODE;
    }

    /** Build the signal from the underlying Redis error (exception or message). */
    public static function from(\Throwable|string $reason): self
    {
        return $reason instanceof \Throwable
            ? new self($reason->getMessage(), 0, $reason)
            : new self($reason);
    }
}
