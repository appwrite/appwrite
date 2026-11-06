<?php

declare(strict_types=1);

namespace Utopia\Cache\Adapter;

use Utopia\Cache\Adapter;

/**
 * Answers reads from a front adapter when it can, and otherwise from the back adapter,
 * remembering what it read. The back holds the truth: a front is shared between layers,
 * so each layer has its own scope, and it only serves an entry for a short time, because
 * another process can purge or replace the back without this one knowing.
 */
class Layered implements Adapter
{
    /**
     * @param  string  $scope prefix of this layer's keys in the front, which is what keeps tenants apart
     * @param  int  $ttl longest in seconds that the front may answer for an entry
     */
    public function __construct(
        private readonly Adapter $front,
        private readonly Adapter $back,
        private readonly string $scope,
        private readonly int $ttl,
    ) {
    }

    public function load(string $key, int $ttl, string $hash = ''): mixed
    {
        $data = $this->front->load($this->scoped($key), \min($ttl, $this->ttl));

        if ($data !== false) {
            return $data;
        }

        $data = $this->back->load($key, $ttl, $hash);

        if (\is_string($data) && $data !== '') {
            $this->front->save($this->scoped($key), $data);
        }

        return $data;
    }

    public function save(string $key, array|string $data, string $hash = '', int $ttl = 0): bool|string|array
    {
        $saved = $this->back->save($key, $data, $hash, $ttl);

        if ($saved === false || ! \is_string($data)) {
            $this->front->purge($this->scoped($key));
        } else {
            $this->front->save($this->scoped($key), $data);
        }

        return $saved;
    }

    public function touch(string $key, string $hash = ''): bool
    {
        return $this->back->touch($key, $hash);
    }

    public function list(string $key): array
    {
        return $this->back->list($key);
    }

    public function purge(string $key, string $hash = ''): bool
    {
        $this->front->purge($this->scoped($key));

        return $this->back->purge($key, $hash);
    }

    /**
     * Flushes the whole front, including other scopes, since it cannot list one. They refill from the back.
     */
    public function flush(): bool
    {
        $this->front->flush();

        return $this->back->flush();
    }

    public function ping(): bool
    {
        return $this->back->ping();
    }

    public function getSize(): int
    {
        return $this->back->getSize();
    }

    public function getName(?string $key = null): string
    {
        return $this->back->getName($key);
    }

    private function scoped(string $key): string
    {
        return $this->scope . "\0" . $key;
    }
}
