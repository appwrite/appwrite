<?php

namespace Appwrite\Locking;

use Utopia\Lock\Distributed;
use Utopia\Lock\Exception\Contention;
use Utopia\Pools\Group;

final class Locks
{
    /**
     * @return callable(string, int, callable, float=): mixed
     */
    public static function using(Group $pools): callable
    {
        return function (string $key, int $ttl, callable $callback, float $timeout = 0.0) use ($pools): mixed {
            $pool = $pools->get('lock');

            try {
                $connection = $pool->pop();
            } catch (\Exception $error) {
                if (\get_class($error) !== \Exception::class) {
                    throw $error;
                }

                throw new Contention($error->getMessage(), previous: $error);
            }

            $failed = false;

            try {
                // The callback receives the lock so long-running holders can refresh
                // the lease and verify it is still theirs before committing work.
                $lock = new Distributed($connection->resource, $key, ttl: $ttl);

                return $lock->withLock(fn () => $callback($lock), timeout: $timeout);
            } catch (\Throwable $error) {
                $failed = true;

                throw $error;
            } finally {
                $pool->release($connection, $failed);
            }
        };
    }
}
