<?php

namespace Appwrite\Databases;

use Appwrite\Extend\Exception;
use Appwrite\Utopia\Database\Attribute;
use Appwrite\Utopia\Database\Validator\CustomId;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Hook\Nested;

/**
 * Validates the relationship values of a write at any depth. Given a read-only filter, it runs on every nested
 * related document, as main stripped them along relationships. Given a nested hook, it prepares every nested related
 * document now rather than when the database writes it, as a staged write must log what its commit will write.
 */
final readonly class RelationshipValues
{
    public function __construct(
        private Database $dbForProject,
        private Document $database,
        private ?\Closure $strip = null,
        private ?Nested $nested = null,
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
                $this->validate($relation);

                if (\is_array($relation) && !\array_is_list($relation)) {
                    $relatedCollection ??= $this->dbForProject->getAuthorization()->skip(fn (): Document => $this->dbForProject->getDocument(
                        'database_' . $this->database->getSequence(),
                        (string) $relationship->getAttribute('relatedCollection', '')
                    ));
                    if ($this->nested !== null) {
                        $relation = $this->nested->prepare($relatedCollection, new Document($relation))->getArrayCopy();
                    }
                    if ($this->strip !== null) {
                        $relation = ($this->strip)($relation);
                    }
                    $relation = $this->prepare($relation, $relatedCollection);
                }

                $relations[$index] = $relation;
            }

            $document[$key] = $isList ? $relations : $relations[0];
        }

        return $document;
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
