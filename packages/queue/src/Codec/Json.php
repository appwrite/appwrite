<?php

declare(strict_types=1);

namespace Utopia\Queue\Codec;

use Utopia\Queue\Codec;

/**
 * The wire format every release so far has written.
 *
 * Objects decode to arrays, which is what the envelope has always done and
 * what {@see \Utopia\Queue\Message} expects.
 */
final class Json implements Codec
{
    public function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function decode(string $value): mixed
    {
        return json_decode($value, true, flags: JSON_THROW_ON_ERROR);
    }

    public function contentType(): string
    {
        return 'application/json';
    }
}
