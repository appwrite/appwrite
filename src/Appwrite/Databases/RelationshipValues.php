<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Attribute;
use Appwrite\Utopia\Database\Validator\CustomId;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Id;
use Utopia\Database\Validator\Authorization;

/**
 * A nested related document without an ID, or with the `unique()` placeholder, gets a generated ID at any depth,
 * because the database library would store `unique()` as the ID itself.
 *
 * Given the database holding the related documents, the permissions a nested document carries are checked against
 * the stored related document, so a caller can only grant roles it holds. API keys and privileged users pass none.
 *
 * Given a read-only filter, it runs on every nested related document, as main stripped them along relationships.
 */
final readonly class RelationshipValues
{
    public function __construct(
        private Database $dbForProject,
        private Document $database,
        private Authorization $authorization,
        private ?Database $dbForDatabases = null,
        private ?\Closure $strip = null,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function prepare(array $document, Document $collection): array
    {
        foreach (Attribute::relationships($collection) as $key => $relationship) {
            $value = $document[$key] ?? null;
            if (empty($value)) {
                continue;
            }

            $isList = \is_array($value) && \array_is_list($value);
            $relations = $isList ? $value : [$value];
            $relatedCollection = null;

            foreach ($relations as $index => $relation) {
                $isDocument = \is_array($relation) && !\array_is_list($relation);

                if ($isDocument && ($relation['$id'] ?? CustomId::UNIQUE) === CustomId::UNIQUE) {
                    $relation['$id'] = Id::unique();
                }

                $this->validate($relation);

                if ($isDocument) {
                    if ($this->strip !== null) {
                        $relation = ($this->strip)($relation);
                    }
                    $relatedCollection ??= $this->authorization->skip(fn (): Document => $this->dbForProject->getDocument(
                        'database_' . $this->database->getSequence(),
                        (string) $relationship->getAttribute('relatedCollection', '')
                    ));
                    $this->validatePermissions($relation, $relatedCollection);
                    $relation = $this->prepare($relation, $relatedCollection);
                }

                $relations[$index] = $relation;
            }

            $document[$key] = $isList ? $relations : $relations[0];
        }

        return $document;
    }

    /**
     * @param array<string, mixed> $relation
     */
    private function validatePermissions(array $relation, Document $relatedCollection): void
    {
        $permissions = $relation['$permissions'] ?? null;
        if ($this->dbForDatabases === null || $permissions === null) {
            return;
        }

        $current = $this->authorization->skip(fn (): Document => $this->dbForDatabases->getDocument(
            'database_' . $this->database->getSequence() . '_collection_' . $relatedCollection->getSequence(),
            $relation['$id']
        ));

        (new RelatedPermissions($this->authorization))->validate($permissions, $current);
    }

    private function validate(mixed $relation): void
    {
        $relationId = null;

        if ($relation instanceof Document) {
            $relationId = $relation->getId();
        } elseif (\is_string($relation)) {
            $relationId = $relation;
        } elseif (\is_array($relation) && !\array_is_list($relation)) {
            $relationId = $relation['$id'] ?? null;
        } else {
            throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, 'Relationship value must be an object, document ID string, or associative array');
        }

        if ($relationId !== null) {
            if (!\is_string($relationId)) {
                throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, 'Relationship $id must be a string');
            }
            $validator = new CustomId();
            if (!$validator->isValid($relationId)) {
                throw new Exception(Exception::RELATIONSHIP_VALUE_INVALID, $validator->getDescription());
            }
        }
    }
}
