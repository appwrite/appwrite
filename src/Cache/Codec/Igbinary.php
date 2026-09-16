<?php

declare(strict_types=1);

namespace Utopia\Cache\Codec;

use RuntimeException;
use Utopia\Cache\Codec;

/**
 * Binary payloads via the `igbinary` extension: smaller and faster than JSON,
 * and a lossless round trip for every PHP value, including empty objects.
 *
 * Payloads are not JSON, so switching an existing cache to this codec turns
 * its stored entries into misses until they are rewritten.
 */
final class Igbinary implements Codec
{
    public function __construct()
    {
        if (! \function_exists('igbinary_serialize')) {
            throw new RuntimeException('The igbinary extension is required for the Igbinary codec.');
        }
    }

    public function encode(mixed $value): string
    {
        return igbinary_serialize($value) ?? throw new RuntimeException('igbinary could not serialize the value.');
    }

    public function decode(string $value): mixed
    {
        // Malformed input is reported as a warning (and null), which would be
        // indistinguishable from a stored null; an empty string fails silently.
        if ($value === '') {
            throw new RuntimeException('Value is not an igbinary payload.');
        }

        set_error_handler(static fn(int $severity, string $message): never => throw new RuntimeException($message));
        try {
            return igbinary_unserialize($value);
        } finally {
            restore_error_handler();
        }
    }
}
