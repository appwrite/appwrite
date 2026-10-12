<?php

namespace Tests\Compat\Query;

require_once __DIR__ . '/Replay.php';

use Tests\Compat\Adapter as Base;
use Tests\Compat\Session;

/**
 * Maps tests/compat/query/spec.json operations onto the PHP library. Glue only: no logic.
 *
 * Ported tests are programs (see Replay.php): the calls each PHP test made on
 * the library, recorded by tests/compat/query/record. Every area's operation
 * replays one; they differ only in which part of the library they cover.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        $replay = fn (array $a, Session $s) => (new Replay())->run($a['program'] ?? [], $a['impls'] ?? []);

        return [
            'query' => $replay,
            'builder' => $replay,
            'schema' => $replay,
            'ast' => $replay,
            'tokenizer' => $replay,
            'classifier' => $replay,
            'hook' => $replay,
        ];
    }
}
