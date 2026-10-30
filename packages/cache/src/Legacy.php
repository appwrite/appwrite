<?php

namespace Utopia\Cache;

use Utopia\Cache\Exception\Unavailable;

/** Temporary cache-5 wire facade. Never used by the ordinary v2 cache path. */
final class Legacy extends Cache
{
    public function save(string $key, mixed $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        if ($ttl !== 0 || (! \is_array($data) && ! \is_string($data))) {
            throw new Unavailable('Legacy migration requires non-expiring string or array values');
        }

        return $this->saveWithLease($key, $data, $hash, '*');
    }

    public function getLease(string $key): string
    {
        return $this->legacyAdapter()->getGeneration($this->caseSensitive ? $key : \strtolower($key));
    }

    public function saveWithLease(string $key, array|string $data, string $hash, string $version): bool|string|array
    {
        $key = $this->caseSensitive ? $key : \strtolower($key);
        $hash = $this->caseSensitive ? $hash : \strtolower($hash);

        return $this->legacyAdapter()->saveWithGeneration($key, $data, $hash, $version) ? $data : false;
    }

    public function purge(string $key, string $hash = ''): bool
    {
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
