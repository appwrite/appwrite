<?php

namespace Appwrite\Utopia\Database\Hooks;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Decorator;
use Utopia\Database\Query;
use Utopia\Query\Schema\ColumnType;

/**
 * Stamps database/collection metadata onto every document returned from the database,
 * and recursively decorates nested relationship documents.
 */
class Metadata implements Decorator
{
    /** @var array<string, array<Document>> */
    private array $relationshipCache = [];

    /** @var array<string, string> */
    private array $publicIds = [];

    private int $operations = 0;

    /**
     * @param  callable(string): string|null  $resolvePublicId
     */
    public function __construct(
        private Document $database,
        private string $context = 'collection',
        private $resolvePublicId = null,
    ) {
    }

    public function decorate(Event $event, Document $collection, Document $document): Document
    {
        if ($document->isEmpty() || $collection->getId() === '_metadata') {
            return $document;
        }

        $this->operations++;

        $collectionId = $this->publicId($collection->getId());
        $document->setAttribute('$databaseId', $this->database->getId());
        $document->setAttribute('$' . $this->context . 'Id', $collectionId);

        $this->decorateRelationships($collection, $document);

        return $document;
    }

    public function getOperations(): int
    {
        return $this->operations;
    }

    public function resetOperations(): void
    {
        $this->operations = 0;
    }

    public static function resolvePublicId(Database $dbForProject, string $internalId): string
    {
        $parts = \explode('_', $internalId);
        if (count($parts) !== 4 || $parts[0] !== 'database' || $parts[2] !== 'collection' || $parts[1] === '' || $parts[3] === '') {
            return $internalId;
        }
        $document = $dbForProject->silent(
            fn () => $dbForProject->getAuthorization()->skip(
                fn () => $dbForProject->findOne('database_'.$parts[1], [
                    Query::equal('$sequence', [$parts[3]]),
                ])
            )
        );
        if ($document->isEmpty()) {
            return $internalId;
        }
        $id = $document->getId();
        return $id !== '' ? $id : $internalId;
    }

    private function decorateRelationships(Document $collection, Document $document, int $depth = 0): void
    {
        if ($depth >= Database::RELATION_MAX_DEPTH) {
            return;
        }

        $parentPublicId = $this->publicId($collection->getId());
        $relationships = $this->getRelationships($collection->getId(), $collection);

        foreach ($relationships as $relationship) {
            $key = $relationship->getAttribute('key');
            $related = $document->getAttribute($key);

            if (empty($related)) {
                if (\in_array(\gettype($related), ['array', 'object'])) {
                    $this->operations++;
                }
                continue;
            }

            $relations = \is_array($related) ? $related : [$related];

            foreach ($relations as $relation) {
                if ($relation instanceof Document) {
                    $this->operations++;
                    $relation->setAttribute('$databaseId', $this->database->getId());
                    $relatedInternalId = $relation->getCollection();
                    $relation->setAttribute(
                        '$' . $this->context . 'Id',
                        $relatedInternalId !== ''
                            ? $this->publicId($relatedInternalId)
                            : $parentPublicId
                    );
                }
            }
        }
    }

    /**
     * @return array<Document>
     */
    private function getRelationships(string $collectionId, Document $collection): array
    {
        if (!isset($this->relationshipCache[$collectionId])) {
            $this->relationshipCache[$collectionId] = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attr) => $attr->getAttribute('type') === ColumnType::Relationship->value
            );
        }

        return $this->relationshipCache[$collectionId];
    }

    private function publicId(string $internalId): string
    {
        if (!isset($this->publicIds[$internalId])) {
            $resolver = $this->resolvePublicId;
            $this->publicIds[$internalId] = $resolver !== null
                ? $resolver($internalId)
                : $internalId;
        }

        return $this->publicIds[$internalId];
    }
}
