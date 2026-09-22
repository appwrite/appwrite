<?php

declare(strict_types=1);

namespace Utopia\Pools;

use Exception;

class Group
{
    /**
     * @var array<Pool<covariant mixed>>
     */
    protected array $pools = [];

    /**
     * @param  Pool<covariant mixed>  $pool
     */
    public function add(Pool $pool): static
    {
        $this->pools[$pool->name] = $pool;

        return $this;
    }

    /**
     * @return Pool<covariant mixed>
     *
     * @throws Exception
     */
    public function get(string $name): Pool
    {
        return $this->pools[$name] ?? throw new Exception("Pool '$name' not found");
    }

    public function remove(string $name): static
    {
        unset($this->pools[$name]);

        return $this;
    }

    public function reclaim(): static
    {
        foreach ($this->pools as $pool) {
            $pool->reclaim();
        }

        return $this;
    }

    /**
     * Execute a callback with a managed connection
     *
     * @template TReturn
     *
     * @param  array<string>  $names  Name of resources
     * @param  callable(mixed...): TReturn  $callback  Function that receives the connection resources
     * @return TReturn Return value from the callback
     *
     * @throws Exception
     */
    public function use(array $names, callable $callback): mixed
    {
        if ($names === []) {
            throw new Exception('Cannot use with empty names');
        }

        $connections = [];
        $pools = [];
        $started = false;
        $failed = false;
        $thrown = null;
        $result = null;

        try {
            foreach ($names as $name) {
                $pool = $this->get($name);
                $pools[] = $pool;
                $connections[] = $pool->pop();
            }

            $started = true;
            $result = $callback(...array_map(fn(Connection $connection): mixed => $connection->resource, $connections));
        } catch (\Throwable $error) {
            $thrown = $error;
            $failed = $started;
        }

        $releaseError = null;

        for ($i = \count($connections) - 1; $i >= 0; --$i) {
            try {
                $pools[$i]->release($connections[$i], $failed);
            } catch (\Throwable $error) {
                $releaseError ??= $error;
            }
        }

        if ($thrown instanceof \Throwable) {
            throw $thrown;
        }

        if ($releaseError instanceof \Throwable) {
            throw $releaseError;
        }

        return $result;
    }
}
