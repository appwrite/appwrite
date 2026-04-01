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
 * Collection ID mapping is pre-populated by getDatabasesDB via setCollectionId()
 * and cached statically per database sequence for the Swoole worker lifetime.
 */
class Metadata implements Decorator
{
    /** @var array<string, array<Document>> */
    private array $relationshipCache = [];

    /** @var array<string, string> internal collection name -> user-facing collection ID */
    private array $collectionIdMap = [];

    /** @var array<string, array<string, string>> static cache keyed by database sequence */
    private static array $staticMaps = [];

    private int $operations = 0;

    public function __construct(
        private Document $database,
        private string $context = 'collection',
    ) {
    }

    /**
     * Register a mapping from internal collection name to user-facing collection ID.
     * Also updates the static cache for subsequent requests.
     */
    public function setCollectionId(string $internalName, string $externalId): void
    {
        $this->collectionIdMap[$internalName] = $externalId;
        $seq = $this->database->getSequence();
        self::$staticMaps[$seq][$internalName] = $externalId;
    }

    /**
     * Get the cached map for a database sequence, or null if not cached.
     *
     * @return array<string, string>|null
     */
    public static function getCachedMap(?string $sequence): ?array
    {
        if ($sequence === null) {
            return null;
        }
        return self::$staticMaps[$sequence] ?? null;
    }

    public function decorate(Event $event, Document $collection, Document $document): Document
    {
        if ($document->isEmpty() || $collection->getId() === '_metadata') {
            return $document;
        }

        $this->operations++;

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
