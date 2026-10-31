<?php

declare(strict_types=1);

namespace Utopia\Cache;

use Utopia\Cache\Exception\Unavailable;

/** Temporary cache-5 wire facade. Never used by the ordinary v2 cache path. */
final class Legacy extends Cache
{
    public function save(string $key, mixed $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if (! \is_array($data) && ! \is_string($data)) {
            throw new Unavailable('Legacy migration requires string or array values');
        }

        return empty($data) ? false : $this->saveWithGeneration($key, $data, $hash, '*', $ttl);
    }

    /** @return string[] */
    public function list(string $key): array
    {
        return \array_values(\array_filter(parent::list($key), fn (string $field): bool => $field !== '__utopia_gen__'));
    }

    public function touch(string $key, string $hash = ''): bool
    {
        throw new Unavailable('Legacy migration does not support touch');
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>|false
     */
    public function saveMany(string $key, array $data, int $ttl = 0): array|false
    {
        $saved = [];
        foreach ($data as $field => $value) {
            if (! \is_array($value) && ! \is_string($value)) {
                return false;
            }
            $result = $this->saveWithGeneration($key, $value, (string) $field, '*', $ttl);
            if ($result === false) {
                return false;
            }
            $saved[$field] = $result;
        }

        return $saved;
    }

    public function supportsVersioning(): bool
    {
        return false;
    }

    /** @return array{version: string, value: mixed} */
    public function loadVersioned(string $key): array
    {
        throw new Unavailable('Opaque snapshots cannot use the legacy keyspace');
    }

    /** @param array<int|string, mixed>|string $data */
    public function compareAndSwap(string $key, array|string $data, string $version): string|false
    {
        throw new Unavailable('Opaque CAS cannot use the legacy keyspace');
    }

    public function getLease(string $key): string
    {
        return $this->legacyAdapter()->getGeneration($this->caseSensitive ? $key : \strtolower($key));
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $version): bool|string|array
    {
        return empty($data) ? false : $this->saveWithGeneration($key, $data, $hash, $version);
    }

    /**
     * @param array<int|string, mixed>|string $data
     * @return bool|string|array<int|string, mixed>
     */
    private function saveWithGeneration(string $key, array|string $data, string $hash, string $version, int $ttl = 0): bool|string|array
    {
        if ($key === '' || $key === '0') {
            return false;
        }
        $key = $this->caseSensitive ? $key : \strtolower($key);
        $hash = $hash === '0' ? '' : $hash;
        $hash = $this->caseSensitive ? $hash : \strtolower($hash);

        return $this->legacyAdapter()->saveWithGeneration($key, $data, $hash, $version, $ttl) ? $data : false;
    }

    public function purge(string $key, string $hash = ''): bool
    {
        $hash = $hash === '0' ? '' : $hash;
        $this->recordPurge($key, $hash);

        return $this->legacyAdapter()->purgeLegacy(
            $this->caseSensitive ? $key : \strtolower($key),
            $this->caseSensitive ? $hash : \strtolower($hash),
        );
    }

    private function legacyAdapter(): Feature\Legacy
    {
        if (! $this->adapter instanceof Feature\Legacy) {
            throw new Unavailable('Legacy migration requires a compatible Redis adapter');
        }

        return $this->adapter;
    }
}
