<?php

/*
 * Shadows of the clock functions the brokers call unqualified (see Clock).
 * Declared before any broker code runs, so the engine resolves those call
 * sites to these.
 */

namespace Utopia\Queue\Broker;

use Tests\Compat\Queue\Clock;

if (!\function_exists(__NAMESPACE__ . '\time')) {
    function time(): int
    {
        return Clock::time();
    }

    function uniqid(string $prefix = '', bool $more_entropy = false): string
    {
        return Clock::uniqid($prefix, $more_entropy);
    }

    /** @return non-empty-string */
    function random_bytes(int $length): string
    {
        $bytes = Clock::randomBytes($length);

        return $bytes === '' ? "\0" : $bytes;
    }
}
