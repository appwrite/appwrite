<?php

declare(strict_types=1);

namespace Utopia\NATS\Tests\Support;

/**
 * A stream that accepts one byte per write, so a transport's short-write loop is
 * guaranteed more than one pass, and that runs a callback after the first of
 * them -- the seam a concurrent close() would land in.
 */
final class ShortWriteStream
{
    /** Fired once, after the first write, to stand in for the racing close(). */
    public static ?\Closure $afterFirstWrite = null;

    /** @var resource|null Set by PHP for stream context; unused here. */
    public $context;

    private int $writes = 0;

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        $this->writes++;

        // PHP drives stream_write() in a loop inside one fwrite() call until
        // everything is accepted, so accepting a byte and then refusing is what
        // makes fwrite() itself return short -- which is the only thing the
        // transport's own loop responds to.
        if ($this->writes > 1) {
            return 0;
        }

        if (self::$afterFirstWrite instanceof \Closure) {
            $callback = self::$afterFirstWrite;
            self::$afterFirstWrite = null;
            $callback();
        }

        return min(1, \strlen($data));
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return [];
    }

    // PHP passes three arguments; none of them matter to a stream that only has
    // to accept a timeout being set on it.
    public function stream_set_option(): bool
    {
        return true;
    }
}
