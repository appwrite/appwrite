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
    private array $relationshipCache = [];

    /** @var array<string, string> internal collection name → user-facing collection ID */
    private array $collectionIdMap = [];

    private bool $mapLoaded = false;

    private int $operations = 0;

    public function __construct(
        private Document $database,
        private string $context = 'collection',
        private ?Database $dbForProject = null,
        private ?Authorization $authorization = null,
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

        $collectionId = $this->resolveCollectionId($collection->getId());
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
     * Resolve internal collection name to user-facing ID.
     * Loads mapping lazily on first miss, caches for lifetime.
     */
    private function resolveCollectionId(string $internalName): string
    {
        if (isset($this->collectionIdMap[$internalName])) {
            return $this->collectionIdMap[$internalName];
        }

        // Strip database prefix if present (e.g., 'database_2_collection_15' → 'collection_15')
        $databasePrefix = 'database_' . $this->database->getSequence() . '_';
        $relativeName = \str_starts_with($internalName, $databasePrefix)
            ? \substr($internalName, \strlen($databasePrefix))
            : $internalName;

        if (isset($this->collectionIdMap[$relativeName])) {
            $this->collectionIdMap[$internalName] = $this->collectionIdMap[$relativeName];
            return $this->collectionIdMap[$relativeName];
        }

        if (!$this->mapLoaded && $this->dbForProject !== null && $this->authorization !== null) {
            $this->mapLoaded = true;
            $this->loadCollectionMap();
            if (isset($this->collectionIdMap[$relativeName])) {
                $this->collectionIdMap[$internalName] = $this->collectionIdMap[$relativeName];
                return $this->collectionIdMap[$relativeName];
            }
        }

        return $internalName;
    }

    /**
     * Load all collection ID mappings in one query.
     * Uses skipValidation to avoid triggering hooks/events.
     */
    private function loadCollectionMap(): void
    {
        $databaseKey = 'database_' . $this->database->getSequence();

        try {
            $collections = $this->authorization->skip(
                fn () => $this->dbForProject->find($databaseKey, [
                    \Utopia\Database\Query::select(['$id', '$sequence']),
                    \Utopia\Database\Query::limit(5000),
                ])
            );

            foreach ($collections as $col) {
                $seq = $col->getSequence();
                if ($seq !== null) {
                    $this->collectionIdMap['collection_' . $seq] = $col->getId();
                }
            }
        } catch (\Throwable $e) {
            \Utopia\CLI\Console::warning('[Metadata] Failed to load collection map for ' . $databaseKey . ': ' . $e->getMessage());
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
            $relatedExternalId = $this->resolveCollectionId($relatedInternalName);

            foreach ($relations as $relation) {
                if ($relation instanceof Document) {
                    $this->operations++;
                    $relation->setAttribute('$databaseId', $this->database->getId());
                    $relation->setAttribute('$' . $this->context . 'Id', $relatedExternalId);

                    $relatedCollection = $this->getRelatedCollection($relatedInternalName);
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
        if (!isset($this->relationshipCache[$collectionId])) {
            $this->relationshipCache[$collectionId] = \array_filter(
                $collection->getAttribute('attributes', []),
                fn ($attr) => $attr->getAttribute('type') === ColumnType::Relationship->value
            );
        }

        return $this->relationshipCache[$collectionId];
    }

    private function getRelatedCollection(string $internalName): Document
    {
        if (!isset($this->relationshipCache[$internalName]) && $this->dbForProject !== null && $this->authorization !== null) {
            $relatedExternalId = $this->resolveCollectionId($internalName);
            try {
                $relatedCollection = $this->authorization->skip(
                    fn () => $this->dbForProject->getDocument(
                        'database_' . $this->database->getSequence(),
                        $relatedExternalId
                    )
                );

                $this->relationshipCache[$internalName] = \array_filter(
                    $relatedCollection->getAttribute('attributes', []),
                    fn ($attr) => $attr->getAttribute('type') === ColumnType::Relationship->value
                );
            } catch (\Throwable) {
                $this->relationshipCache[$internalName] = [];
            }
        }

        return new Document([
            '$id' => $internalName,
            'attributes' => $this->relationshipCache[$internalName] ?? [],
        ]);
    }
}
