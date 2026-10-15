<?php

declare(strict_types=1);

namespace Utopia\Cache;

/**
 * Turns a cache value into the bytes an adapter stores, and back.
 *
 * Both directions throw on failure: an unencodable value, or bytes that are
 * not a payload this codec wrote. Adapters treat a decode failure as a miss.
 */
interface Codec
{
    /**
     * @throws \Throwable
     */
    public function encode(mixed $value): string;

    /**
     * @throws \Throwable
     */
    public function decode(string $value): mixed;
}
