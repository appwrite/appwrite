<?php

declare(strict_types=1);

namespace Utopia\Cache\Tests;

use RuntimeException;
use Utopia\Cache\Adapter;

class FailingAdapter implements Adapter
{
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        throw new RuntimeException('Cache failed.');
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        throw new RuntimeException('Cache failed.');
    }

    public function touch(string $key, string $hash = ''): bool
    {
        throw new RuntimeException('Cache failed.');
    }

    public function list(string $key): array
    {
        throw new RuntimeException('Cache failed.');
    }

    public function purge(string $key, string $hash = ''): bool
    {
        throw new RuntimeException('Cache failed.');
    }

    public function flush(): bool
    {
        throw new RuntimeException('Cache failed.');
    }

    public function ping(): bool
    {
        throw new RuntimeException('Cache failed.');
    }

    public function getSize(): int
    {
        throw new RuntimeException('Cache failed.');
    }

    public function getName(?string $key = null): string
    {
        return 'failing';
    }
}
