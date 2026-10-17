<?php

/*
 * A fixed clock for the compat runs: the issuers and verifiers call `time()`
 * unqualified, so these namespaced functions answer in their place. Each
 * operation that passes `now` sets it; without it they return the real time.
 */

namespace Tests\Compat\Auth {
    final class Clock
    {
        public static ?int $now = null;

        public static function now(): int
        {
            return self::$now ?? \time();
        }
    }
}

namespace Utopia\Auth {
    function time(): int
    {
        return \Tests\Compat\Auth\Clock::now();
    }
}

namespace Utopia\Auth\Issuers\Symmetric {
    function time(): int
    {
        return \Tests\Compat\Auth\Clock::now();
    }
}

namespace Utopia\Auth\Issuers\Asymmetric {
    function time(): int
    {
        return \Tests\Compat\Auth\Clock::now();
    }
}
