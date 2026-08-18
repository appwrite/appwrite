<?php

namespace Appwrite\Platform\Tasks;

use Utopia\Pools\Group;
use Utopia\Schedule\Claim;
use Utopia\Schedule\Store;

/**
 * The scheduler's claim — leadership plus the committed window — kept in
 * Redis, borrowed from the lock pool for each read and write.
 *
 * The pool is the connection source rather than the legacy `redis` resource
 * because the pool is built from _APP_CONNECTIONS_LOCKS, which deployed
 * environments actually set; the legacy resource reads _APP_REDIS_HOST and
 * would quietly resolve to localhost there, leaving a scheduler that can
 * never take its claim and so never dispatches. Borrowing per call also means
 * a connection that dies is replaced by the pool instead of stranding the
 * claim for the lifetime of the process.
 */
final class ScheduleStore implements Store
{
    public function __construct(
        private readonly Group $pools,
        private readonly string $key,
    ) {
    }

    #[\Override]
    public function load(): ?Claim
    {
        return $this->pools->get('lock')->use(fn (\Redis $redis): ?Claim => (new Store\Redis($redis, $this->key))->load());
    }

    #[\Override]
    public function swap(?string $expected, Claim $next): bool
    {
        return $this->pools->get('lock')->use(fn (\Redis $redis): bool => (new Store\Redis($redis, $this->key))->swap($expected, $next));
    }
}
