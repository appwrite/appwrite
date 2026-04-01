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
 * Lazily loads the collection mapping from dbForProject on first use to resolve
 * internal collection names (database_N_collection_M) to user-facing collection IDs.
 */
class Metadata implements Decorator
{
    /** @var array<string, array<Document>> */
    private array $relationshipCache = [];

    /** @var array<string, string> internal collection name -> user-facing collection ID */
    private array $collectionIdMap = [];

    /** @var bool whether the collection map has been loaded */
    private bool $mapLoaded = false;

    private int $operations = 0;

    public function __construct(
        private Document $database,
        private Database $dbForProject,
        private Authorization $authorization,
        private string $context = 'collection',
    ) {
    }

    /**
     * Register a mapping from internal collection name to user-facing collection ID.
     */
    public function setCollectionId(string $internalName, string $externalId): void
    {
        $this->collectionIdMap[$internalName] = $externalId;
    }

    public function decorate(Event $event, Document $collection, Document $document): Document
    {
        if ($document->isEmpty() || $collection->getId() === '_metadata') {
            return $document;
        }

        $this->operations++;

        $this->ensureMapLoaded();

        $collectionId = $this->collectionIdMap[$collection->getId()] ?? $collection->getId();
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

    /**
     * Lazily load collection mapping from dbForProject.
     * Queries are wrapped in authorization->skip() and dbForProject->silent()
     * to prevent lifecycle hooks from firing and avoid permission checks.
     */
    private function ensureMapLoaded(): void
    {
        if ($this->mapLoaded) {
            return;
        }

        $this->mapLoaded = true;
        $databaseSequence = $this->database->getSequence();
        $metadataCollection = 'database_' . $databaseSequence;

        $collections = $this->authorization->skip(
            fn () => $this->dbForProject->silent(
                fn () => $this->dbForProject->find($metadataCollection)
            )
        );

        foreach ($collections as $collection) {
            $externalId = $collection->getId();
            $sequence = $collection->getSequence();

            $relativeKey = 'collection_' . $sequence;
            $fullKey = 'database_' . $databaseSequence . '_collection_' . $sequence;

            $this->collectionIdMap[$relativeKey] = $externalId;
            $this->collectionIdMap[$fullKey] = $externalId;
        }
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
            $options = $relationship->getAttribute('options', []);
            $relatedInternalName = (\is_array($options) ? ($options['relatedCollection'] ?? null) : null)
                ?? $relationship->getAttribute('relatedCollection');
            $relatedExternalId = $this->collectionIdMap[$relatedInternalName] ?? $relatedInternalName;

            foreach ($relations as $relation) {
                if ($relation instanceof Document) {
                    $this->operations++;
                    $relation->setAttribute('$databaseId', $this->database->getId());
                    $relation->setAttribute('$' . $this->context . 'Id', $relatedExternalId);
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
