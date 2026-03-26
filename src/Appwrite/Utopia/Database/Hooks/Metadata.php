<?php

namespace Appwrite\Utopia\Database\Hooks;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Decorator;
use Utopia\Database\Validator\Authorization;
use Utopia\Query\Schema\ColumnType;

/**
 * Stamps database/collection metadata onto every document returned from the database,
 * and recursively decorates nested relationship documents.
 *
 * Replaces the manual processDocument() loop that previously ran in every endpoint.
 */
class Metadata implements Decorator
{
    /** @var array<string, array<Document>> */
    private array $cache = [];

    private int $operations = 0;

    public function __construct(
        private Document $database,
        private string $context = 'collection',
        private ?Database $dbForProject = null,
        private ?Authorization $authorization = null,
    ) {
    }

    public function decorate(Event $event, Document $collection, Document $document): Document
    {
        if ($document->isEmpty()) {
            return $document;
        }

        $this->operations++;

        $collectionId = $collection->getId();
        $document->removeAttribute('$collection');
        $document->setAttribute('$databaseId', $this->database->getId());
        $document->setAttribute('$' . $this->context . 'Id', $collectionId);

        $this->decorateRelationships($collection, $document);

        return $document;
    }

    /**
     * Get the number of operations (documents + relationship traversals) processed.
     */
    public function getOperations(): int
    {
        return $this->operations;
    }

    /**
     * Reset the operation counter.
     */
    public function resetOperations(): void
    {
        $this->operations = 0;
    }

    private function decorateRelationships(Document $collection, Document $document, int $depth = 0): void
    {
        if ($depth >= Database::RELATION_MAX_DEPTH) {
            return;
        }

        $collectionId = $collection->getId();
        $relationships = $this->getRelationships($collectionId, $collection);

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
            $relatedCollectionId = $relationship->getAttribute('relatedCollection');

            foreach ($relations as $relation) {
                if ($relation instanceof Document) {
                    $this->operations++;
                    $relation->removeAttribute('$collection');
                    $relation->setAttribute('$databaseId', $this->database->getId());
                    $relation->setAttribute('$' . $this->context . 'Id', $relatedCollectionId);

                    $relatedCollection = $this->getRelatedCollection($relatedCollectionId);
                    $this->decorateRelationships($relatedCollection, $relation, $depth + 1);
                }
            }
        }
    }

    /**
     * @return array<Document>
     */
    private function getRelationships(string $collectionId, Document $collection): array
    {
        if (!isset($this->cache[$collectionId])) {
            $this->cache[$collectionId] = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attr) => $attr->getAttribute('type') === ColumnType::Relationship->value
            );
        }

        return $this->cache[$collectionId];
    }

    private function getRelatedCollection(string $collectionId): Document
    {
        if (!isset($this->cache[$collectionId]) && $this->dbForProject !== null && $this->authorization !== null) {
            $relatedCollection = $this->authorization->skip(
                fn () => $this->dbForProject->getDocument(
                    'database_' . $this->database->getSequence(),
                    $collectionId
                )
            );

            $this->cache[$collectionId] = \array_filter(
                $relatedCollection->getAttribute('attributes', []),
                fn ($attr) => $attr->getAttribute('type') === ColumnType::Relationship->value
            );
        }

        return new Document([
            '$id' => $collectionId,
            'attributes' => $this->cache[$collectionId] ?? [],
        ]);
    }
}
