<?php

namespace Appwrite\Locking;

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

    public function tryUpdateAttribute(
        string $collection,
        string $id,
        string $attribute,
        mixed $value,
        ?Document $project = null,
    ): ?Document {
        $key = $project === null
            ? $this->lock->key($collection, $id, $attribute)
            : $this->lock->keyForProject($project, $collection, $id, $attribute);

        /** @var Document|null */
        return $this->lock->tryWithKey(
            $key,
            fn () => $this->authorization->skip(fn () => $this->dbForPlatform->updateDocument(
                $collection,
                $id,
                new Document([$attribute => $value])
            )),
            target: $collection
        );
    }
}
