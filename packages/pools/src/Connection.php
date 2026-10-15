<?php

declare(strict_types=1);

namespace Utopia\Pools;

use WeakReference;

/**
 * A resource checked out of a pool.
 *
 * Immutable: identity, resource and owning pool are fixed at construction. A
 * connection held by one coroutine cannot be repointed at another pool or have
 * its resource swapped underneath it.
 *
 * The reference to the owning pool is weak, because a strong one is a cycle:
 * a pool reaches its idle connections through its adapter, and with the Swoole
 * adapter that path runs through a Coroutine\Channel whose contents live in C
 * memory. PHP's collector cannot traverse a channel, so it could never prove
 * such a cycle unreachable, and the channel's own reference kept the refcounts
 * above zero — every pool, connection and pooled resource stayed alive for the
 * life of the process. Holding the pool weakly keeps the graph acyclic, so
 * dropping a pool releases its connections and their resources by refcount.
 *
 * Reclaiming or destroying a connection whose pool is already gone is a no-op:
 * the pool that owned the capacity no longer exists to account for it.
 *
 * @template TResource
 */
final readonly class Connection
{
    /**
     * @var WeakReference<Pool<TResource>>
     */
    private WeakReference $pool;

    /**
     * @param  TResource  $resource
     * @param  Pool<TResource>  $pool
     */
    public function __construct(
        public string $id,
        public mixed $resource,
        Pool $pool,
    ) {
        $this->pool = WeakReference::create($pool);
    }

    /**
     * Return this connection to its pool for reuse.
     */
    public function reclaim(): void
    {
        $this->pool->get()?->reclaim($this);
    }

    /**
     * Discard this connection and free its capacity for a replacement.
     */
    public function destroy(): void
    {
        $this->pool->get()?->destroy($this);
    }
}
