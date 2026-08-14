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
 * Related documents store the internal collection id; resolveExternalId maps
 * that back to the user-facing collection id.
 */
class Metadata implements Decorator
{
    /** @var array<string, array<Document>> */
    private array $relationshipCache = [];

    /** @var array<string, string> */
    private array $externalIds = [];

    private int $operations = 0;

    /**
     * @param  callable(string): string|null  $resolveExternalId
     */
    public function __construct(
        private Document $database,
        private string $context = 'collection',
        private $resolveExternalId = null,
    ) {
    }

    public function decorate(Event $event, Document $collection, Document $document): Document
    {
        if ($document->isEmpty() || $collection->getId() === '_metadata') {
            return $document;
        }

        $this->operations++;

        $collectionId = $collection->getAttribute('externalId', $collection->getId());
        $this->externalIds[$collection->getId()] = $collectionId;
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

        $parentExternalId = $collection->getAttribute('externalId', $collection->getId());
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
                            ? $this->externalId($relatedInternalId)
                            : $parentExternalId
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

    private function externalId(string $internalId): string
    {
        if (!isset($this->externalIds[$internalId])) {
            $resolver = $this->resolveExternalId;
            $this->externalIds[$internalId] = $resolver !== null
                ? $resolver($internalId)
                : $internalId;
        }

        return $this->externalIds[$internalId];
    }
}
