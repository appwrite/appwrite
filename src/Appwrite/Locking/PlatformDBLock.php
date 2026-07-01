<?php

namespace Appwrite\Locking;

use Closure;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Validator\Authorization;

final class PlatformDBLock
{
    public function __construct(
        private readonly Lock $lock,
        private readonly Database $dbForPlatform,
        private readonly Authorization $authorization,
    ) {
    }

    public function tryRun(
        string $collection,
        string $id,
        Closure $callback,
        ?string $attribute = null,
        ?Document $project = null,
    ): mixed {
        $key = $project === null
            ? $this->lock->key($collection, $id, $attribute)
            : $this->lock->keyForProject($project, $collection, $id, $attribute);

        return $this->lock->tryWithKey($key, $callback, target: $collection);
    }

    public function tryUpdateAttribute(
        string $collection,
        string $id,
        string $attribute,
        mixed $value,
        ?Document $project = null,
    ): ?Document {
        /** @var Document|null */
        return $this->tryRun(
            $collection,
            $id,
            fn () => $this->authorization->skip(fn () => $this->dbForPlatform->updateDocument(
                $collection,
                $id,
                new Document([$attribute => $value])
            )),
            $attribute,
            $project
        );
    }

    /**
     * Document-level counterpart to tryUpdateAttribute() for sparse updates
     * that change several fields at once. Locks at the document key (no
     * attribute suffix) so the whole update is mutually exclusive.
     */
    public function tryUpdateDocument(
        string $collection,
        string $id,
        Document $updates,
        ?Document $project = null,
    ): ?Document {
        /** @var Document|null */
        return $this->tryRun(
            $collection,
            $id,
            fn () => $this->authorization->skip(fn () => $this->dbForPlatform->updateDocument(
                $collection,
                $id,
                $updates
            )),
            project: $project
        );
    }
}
