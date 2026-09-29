<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Unit\Support;

/**
 * Stream wrapper that accepts at most $chunk bytes per write, forcing short
 * writes so the transport write loop can be exercised deterministically.
 */
final class PartialWriteStream
{
    /** @var resource|null */
    public $context;

    public static string $buffer = '';
    public static int $chunk = 3;
    public static int $writeCalls = 0;

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        self::$writeCalls++;
        $n = min(self::$chunk, \strlen($data));
        self::$buffer .= substr($data, 0, $n);

        return $n;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /** Needed so stream_set_blocking()/stream_set_timeout() don't warn. */
    public function stream_set_option(): bool
    {
        return true;
    }
}
