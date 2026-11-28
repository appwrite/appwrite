<?php

namespace Tests\Compat;

/**
 * Maps one library's compat operations (tests/compat/<lib>/spec.json) onto
 * the PHP library. Implemented by tests/compat/<lib>/Adapter.php.
 *
 * Adapters are glue: decode arguments, call the library, return plain values
 * (scalars, arrays, stdClass, handles). They hold no logic of their own.
 */
interface Adapter
{
    /**
     * Operation name => callable(array<string, mixed> $args, Session $session): mixed
     *
     * @return array<string, callable(array<string, mixed>, Session): mixed>
     */
    public function operations(): array;
}
