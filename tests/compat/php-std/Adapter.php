<?php

namespace Tests\Compat\PhpStd;

use Tests\Compat\Adapter as Base;

/**
 * PHP engine functions, called directly: the oracle for crates/support/php-std.
 * Operations live in ops/<area>.php, one file per area, each returning
 * `name => callable(array $args, Session $session): mixed`.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        $operations = [];
        foreach (glob(__DIR__ . '/ops/*.php') ?: [] as $file) {
            $operations += require $file;
        }

        return $operations;
    }
}
