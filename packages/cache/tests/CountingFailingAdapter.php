<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

class CountingFailingAdapter extends FailingAdapter
{
    public int $loads = 0;

    public int $purges = 0;

    public function purge(string $key, string $hash = ''): bool
    {
        $this->purges++;

        return parent::purge($key, $hash);
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $this->loads++;

        return parent::load($key, $ttl, $hash);
    }
}
