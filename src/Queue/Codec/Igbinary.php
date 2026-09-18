<?php

declare(strict_types=1);

namespace Utopia\Queue\Codec;

use RuntimeException;
use Utopia\Queue\Codec;

/**
 * Binary envelopes via the `igbinary` extension: smaller on the wire and
 * several times faster to decode than JSON, which on a queue is paid once per
 * message per delivery.
 *
 * Payloads are not JSON, so a broker configured with this codec alone cannot
 * read a message an earlier release wrote. {@see Compat} is what makes the
 * switch survivable.
 */
final class Igbinary implements Codec
{
    public function __construct()
    {
        if (!\function_exists('igbinary_serialize')) {
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
        // indistinguishable from an encoded null; an empty string fails silently.
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

    public function contentType(): string
    {
        return 'application/vnd.php.igbinary';
    }
}
