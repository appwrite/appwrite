<?php

namespace Appwrite\Presences;

use Utopia\Lock\Lock as UtopiaLock;
use Utopia\Lock\Mutex;

final class Lock
{
    private const STRIPES = 256;

    /** @var array<int, UtopiaLock> */
    private array $stripes = [];

    public function get(string $key): UtopiaLock
    {
        $index = \abs(\crc32($key) % self::STRIPES);

        return $this->stripes[$index] ??= new Mutex();
    }
}
