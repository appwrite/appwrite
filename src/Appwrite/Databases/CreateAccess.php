<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\PermissionType;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;

/**
 * Main's document create gate: the collection must grant the caller create, and every nested related document must
 * have its collection grant create when new or update when it exists. An existing related document is refused
 * outright when its collection has no document security, whoever the caller is.
 */
final readonly class CreateAccess
{
    public function __construct(
        private Authorization $authorization,
        private Database $dbForDatabases,
        private Document $database,
    ) {
    }

    public function assert(Document $collection, PermissionType $type): void
    {
        $granted = $this->authorization->isValid(new Input($type, $collection->getPermissionsByType($type)));

        if (($type === PermissionType::Update && !$collection->getAttribute('documentSecurity', false)) || !$granted) {
            throw new Exception(Exception::USER_UNAUTHORIZED, $this->authorization->getDescription());
        }
    }

    /**
     * @param array<string, mixed> $relation
     */
    public function assertRelated(array $relation, Document $relatedCollection): void
    {
        $current = $this->authorization->skip(fn (): Document => $this->dbForDatabases->getDocument(
            'database_' . $this->database->getSequence() . '_collection_' . $relatedCollection->getSequence(),
            $relation['$id']
        ));

        $this->assert($relatedCollection, $current->isEmpty() ? PermissionType::Create : PermissionType::Update);
    }
}
