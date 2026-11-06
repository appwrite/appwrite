<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

/**
 * Keeps entries in this process's memory up to a total size, dropping the least
 * recently used. An entry over the per-entry limit is not kept, and a value that
 * is not a string is not kept either.
 */
class Bounded implements Adapter
{
    /** @var array<string, array{time: int, data: string}> Least recently used first */
    private array $store = [];

    private int $size = 0;

    /**
     * @param  int  $capacity total bytes of keys and values to keep
     * @param  int  $limit most bytes of one value to keep
     */
    public function __construct(
        private readonly int $capacity,
        private readonly int $limit,
    ) {
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $entry = $this->store[$key] ?? null;

        if ($entry === null) {
            return false;
        }

        if ($entry['time'] + $ttl <= \time()) {
            $this->purge($key);

            return false;
        }

        unset($this->store[$key]);
        $this->store[$key] = $entry;

        return $entry['data'];
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        $this->purge($key);

        $cost = \is_string($data) ? \strlen($key) + \strlen($data) : 0;

        if (! \is_string($data) || $data === '' || \strlen($data) > $this->limit || $cost > $this->capacity) {
            return false;
        }

        $this->size += $cost;
        $this->store[$key] = ['time' => \time(), 'data' => $data];

        while ($this->size > $this->capacity) {
            $this->purge(\array_key_first($this->store));
        }

        return $data;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        if (! isset($this->store[$key])) {
            return false;
        }

        $this->store[$key]['time'] = \time();

        return true;
    }

    public function list(string $key): array
    {
        return [];
    }

    public function purge(string $key, string $hash = ''): bool
    {
        if (! isset($this->store[$key])) {
            return false;
        }

        $this->size -= \strlen($key) + \strlen($this->store[$key]['data']);
        unset($this->store[$key]);

        return true;
    }

    public function flush(): bool
    {
        $this->store = [];
        $this->size = 0;

        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getName(?string $key = null): string
    {
        return 'bounded';
    }
}
