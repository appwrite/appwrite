<?php

namespace Appwrite\Utopia\Database\Hooks;

use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Event;
use Utopia\Database\Hook\Decorator;
use Utopia\Query\Schema\ColumnType;

/**
 * Stamps database/collection metadata onto every document returned from the database,
 * and recursively decorates nested relationship documents.
 *
 * Uses the 'externalId' attribute stored on the collection metadata document
 * to resolve internal collection names to user-facing collection IDs.
 * Zero queries — externalId is set during createCollection.
 */
class Metadata implements Decorator
{
    /** @var array<string, array<Document>> */
    private array $relationshipCache = [];

    private int $operations = 0;

    public function __construct(
        private Document $database,
        private string $context = 'collection',
    ) {
    }

    public function decorate(Event $event, Document $collection, Document $document): Document
    {
        if ($document->isEmpty() || $collection->getId() === '_metadata') {
            return $document;
        }

        $this->operations++;

        $collectionId = $collection->getAttribute('externalId', $collection->getId());
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

            foreach ($relations as $relation) {
                if ($relation instanceof Document) {
                    $this->operations++;
                    $relation->setAttribute('$databaseId', $this->database->getId());
                    // Related documents get their $collection set by the database library
                    // which points to the related collection's metadata — read its externalId
                    $relCollection = $relation->getCollection();
                    $relation->setAttribute('$' . $this->context . 'Id', $relCollection ?: $collectionId);
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
}
