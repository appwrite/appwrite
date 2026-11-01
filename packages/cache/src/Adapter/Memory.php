<?php

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

class Memory implements Adapter, \Utopia\Cache\Feature\Versioned
{
    public function supportsVersioning(): bool
    {
        return true;
    }

    public function getVersion(string $key): string
    {
        $this->store[$key] ??= ['time' => \time(), 'data' => false];

        return $this->store[$key]['version'] ??= \bin2hex(\random_bytes(16));
    }

    /** @return array{version: string, value: mixed} */
    public function loadVersioned(string $key): array
    {
        return ['version' => $this->getVersion($key), 'value' => $this->load($key, PHP_INT_MAX)];
    }

    /** @param array<int|string, mixed>|string $data */
    public function compareAndSwap(string $key, array|string $data, string $hash, string $version): string|false
    {
        if ($this->getVersion($key) !== $version) {
            return false;
        }
        if ($this->save($key, $data, $hash) === false) {
            throw new \Utopia\Cache\Exception\Unavailable('Unable to save cache snapshot');
        }

        return $this->store[$key]['version'] = \bin2hex(\random_bytes(16));
    }

    /**
     * @var array<string, array{time: int, data: mixed, version?: string}>
     */
    public $store = [];

    /**
     * @param  int  $ttl time in seconds
     * @param  string  $hash optional
     */
    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        if ($key !== '' && $key !== '0' && isset($this->store[$key])) {
            /** @var array{time: int, data: string} */
            $saved = $this->store[$key];

            return ($saved['time'] + $ttl > time()) ? $saved['data'] : false; // return data if cache is valid
        }

        return false;
    }

    /**
     * @param  array<int|string, mixed>|string  $data
     * @param  string  $hash optional
     * @param  int  $ttl time in seconds
     * @return bool|string|array<int|string, mixed>
     */
    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if ($key === '' || $key === '0' || empty($data)) {
            return false;
        }

        $saved = [
            'time' => time(),
            'data' => $data,
            'version' => \bin2hex(\random_bytes(16)),
        ];

        $this->store[$key] = $saved;

        return $data;
    }

    /**
     * @param  string  $hash optional
     */
    public function touch(string $key, string $hash = ''): bool
    {
        if ($key === '' || $key === '0' || ! isset($this->store[$key])) {
            return false;
        }

        /** @var array{time: int, data: string|array<int|string, mixed>} $saved */
        $saved = $this->store[$key];
        $saved['time'] = time();
        $saved['version'] = \bin2hex(\random_bytes(16));
        $this->store[$key] = $saved;

        return true;
    }

    /**
     * @return string[]
     */
    public function list(string $key): array
    {
        return [];
    }

    /**
     * @param  string  $hash optional
     */
    public function purge(string $key, string $hash = ''): bool
    {
        if ($key !== '' && $key !== '0' && isset($this->store[$key])) { // if a key is passed and it exists in cache
            unset($this->store[$key]);

            return true;
        }

        return false;
    }

    public function flush(): bool
    {
        $this->store = [];

        return true;
    }

    public function ping(): bool
    {
        return true;
    }

    /**
     * Returning total number of keys
     */
    public function getSize(): int
    {
        return \count($this->store);
    }

    public function getName(?string $key = null): string
    {
        return 'memory';
    }
}
