<?php

namespace Appwrite\Locking;

use Closure;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class Lock
{
    public function __construct(
        private readonly Closure $skipLock,
        private readonly Closure $failLock,
        private readonly Database $dbForPlatform,
        private readonly Authorization $authorization,
    ) {}

    /**
     * Throttled single-attribute write under a skip-on-contention lock with
     * authorization bypass. For idempotent timestamp-style updates (accessedAt,
     * mcpAccessedAt) where regional pods writing the same value would thrash
     * the platform DB.
     */
    public function set(
        string $collection,
        string $id,
        string $attribute = 'accessedAt',
        ?string $value = null,
    ): void {
        ($this->skipLock)(self::key($collection, $id), function () use ($collection, $id, $attribute, $value) {
            $this->authorization->skip(fn () => $this->dbForPlatform->updateDocument(
                $collection,
                $id,
                new Document([$attribute => $value ?? DateTime::now()])
            ));
        });
    }

    /**
     * Skip-on-contention lock around an arbitrary callback. For idempotent
     * writes that don't fit the set shape (e.g., updates with cache purge).
     */
    public function run(string $collection, string $id, Closure $fn): void
    {
        ($this->skipLock)(self::key($collection, $id), $fn);
    }

    /**
     * Block-then-409 lock around an arbitrary callback. For read-modify-write
     * endpoints where silently dropping a concurrent request would lose data.
     */
    public function runOrFail(string $collection, string $id, Closure $fn): mixed
    {
        return ($this->failLock)(self::key($collection, $id), $fn);
    }

    private static function key(string $collection, string $id): string
    {
        return "lock:platform:{$collection}:{$id}";
    }
}
