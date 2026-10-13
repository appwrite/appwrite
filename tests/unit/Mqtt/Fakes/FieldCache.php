<?php

declare(strict_types=1);

namespace Tests\Unit\Mqtt\Fakes;

use Utopia\Cache\Adapter;
use Utopia\Cache\Feature\Batchable;

/** An in-memory hash cache on an injected clock that expires and misses fields the way the Redis adapter does. */
final class FieldCache implements Adapter, Batchable
{
    /** @var array<string, array<string, array{value: mixed, writtenAt: int}>> key => field => entry */
    public array $fields = [];

    /** @var array<string, int> key => the last positive TTL passed to saveMany */
    public array $ttls = [];

    /** @param \Closure(): int $clock */
    public function __construct(private \Closure $clock)
    {
    }

    public function seed(string $key, string $field, mixed $value, int $writtenAt): void
    {
        $this->fields[$key][$field] = ['value' => $value, 'writtenAt' => $writtenAt];
    }

    public function loadMany(string $key, array $fields, int $ttl): array
    {
        $entries = $this->fields[$key] ?? [];
        if ($fields !== []) {
            $entries = \array_intersect_key($entries, \array_flip($fields));
        }

        $now = ($this->clock)();
        $values = [];
        foreach ($entries as $field => $entry) {
            if ($entry['value'] !== null && $entry['writtenAt'] + $ttl > $now) {
                $values[$field] = $entry['value'];
            }
        }

        return $values;
    }

    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        if ($data === []) {
            return false;
        }

        foreach ($data as $field => $value) {
            $this->seed($key, (string) $field, $value, ($this->clock)());
        }

        if ($ttl > 0) {
            $this->ttls[$key] = $ttl;
        }

        return $data;
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $field = $hash ?: $key;

        return $this->loadMany($key, [$field], $ttl)[$field] ?? false;
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if ($data === [] || $data === '' || $data === '0') {
            return false;
        }

        return $this->saveMany($key, [$hash ?: $key => $data], $ttl) === false ? false : $data;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        $field = $hash ?: $key;
        if (!isset($this->fields[$key][$field])) {
            return false;
        }

        $this->fields[$key][$field]['writtenAt'] = ($this->clock)();

        return true;
    }

    public function list(string $key): array
    {
        return \array_map(strval(...), \array_keys($this->fields[$key] ?? []));
    }

    public function purge(string $key, string $hash = ''): bool
    {
        if ($hash === '') {
            $existed = isset($this->fields[$key]);
            unset($this->fields[$key], $this->ttls[$key]);

            return $existed;
        }

        $existed = isset($this->fields[$key][$hash]);
        unset($this->fields[$key][$hash]);

        return $existed;
    }

    public function flush(): bool
    {
        $this->fields = [];
        $this->ttls = [];

        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    public function getSize(): int
    {
        return \count($this->fields);
    }

    public function getName(?string $key = null): string
    {
        return 'field';
    }
}
