<?php

namespace Tests\Compat\Vcs;

use Tests\Compat\Adapter as Base;
use Tests\Compat\Session;

/**
 * Maps tests/compat/vcs/spec.json operations onto the PHP library. Glue only: no logic.
 */
final class Adapter implements Base
{
    public function operations(): array
    {
        return [
        ];
    }
}
