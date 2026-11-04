<?php

declare(strict_types=1);

namespace Tests\Unit\Databases;

use Utopia\Cache\Cache;

/**
 * A cache that runs a callback just before it writes list fields, standing in for another request
 * landing between a list read's version check and its own write.
 */
final class Interleaved extends Cache
{
    /** @var null|callable(): void */
    public $before = null;

    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        $before = $this->before;
        $this->before = null;

        if ($before !== null) {
            $before();
        }

        return parent::saveMany($key, $data, $ttl);
    }
}
