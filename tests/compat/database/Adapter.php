<?php

namespace Tests\Compat\Database;

use Tests\Compat\Adapter as Base;

require_once __DIR__ . '/Values.php';

/**
 * Maps tests/compat/database/spec.json operations onto utopia-php/database.
 * Glue only. Operations live in ops/<area>.php, one file per area, each
 * returning `name => callable(array $args, Session $session): mixed`.
 * Arguments and results cross through Values (tagged documents, queries,
 * operators and objects).
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
