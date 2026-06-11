<?php

namespace Appwrite\Locking;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class PlatformLock
{
    public function __construct(
        private readonly Lock $lock,
        private readonly Database $dbForPlatform,
        private readonly Authorization $authorization,
    ) {
    }

    /**
     * Throttled single-attribute write under a per-attribute skip-on-contention
     * lock with authorization bypass. For idempotent timestamp-style updates
     * (accessedAt, mcpAccessedAt) where regional pods writing the same value
     * would thrash the platform DB.
     */
    public function set(
        string $collection,
        string $id,
        string $attribute,
        string $value,
    ): void {
        $this->lock->withKey(
            $this->lock->key($collection, $id, $attribute),
            function () use ($collection, $id, $attribute, $value): void {
                $this->authorization->skip(fn () => $this->dbForPlatform->updateDocument(
                    $collection,
                    $id,
                    new Document([$attribute => $value])
                ));
            },
            target: $collection,
        );
    }
}
