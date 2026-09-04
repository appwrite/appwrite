<?php

namespace Appwrite\Utopia\Database\Hooks;

use Appwrite\Utopia\Database\Adapter\Pool;
use Closure;
use Override;
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

    /** @var Closure(string): string|null */
    private readonly ?Closure $resolvePublicId;

    /**
     * @param  callable(string): string|null  $resolvePublicId
     */
    public function __construct(
        private readonly Document $database,
        private readonly string $context = 'collection',
        ?callable $resolvePublicId = null,
        private readonly ?Database $tenant = null,
    ) {
        $this->resolvePublicId = $resolvePublicId === null ? null : $resolvePublicId(...);
    }

    #[Override]
    public function decorate(Event $event, Document $collection, Document $document): Document
    {
        if ($document->isEmpty() || $collection->getId() === '_metadata') {
            return $document;
        }

        $this->operations++;

        $collectionId = $this->publicId($collection->getId());
        $document->setAttribute('$databaseId', $this->database->getId());
        $document->setAttribute('$' . $this->context . 'Id', $collectionId);

        $this->getRelationships($collection->getId(), $collection);
        $this->decorateRelationships($collection->getId(), $document);

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

    /**
     * @param  array<string, string>  $publicIds
     * @return \Closure(string): string
     */
    public static function resolver(Database $tenant, ?Database $catalog = null, array $publicIds = []): \Closure
    {
        return function (string $internalId) use ($tenant, $catalog, $publicIds): string {
            if (isset($publicIds[$internalId])) {
                return $publicIds[$internalId];
            }

            $database = $catalog !== null && (
                ! $tenant->getAdapter()->inTransaction()
                || ! self::sharesPool($tenant, $catalog)
            )
                ? $catalog
                : $tenant;

            return self::resolvePublicId($database, $internalId);
        };
    }

    private static function sharesPool(Database $tenant, Database $catalog): bool
    {
        $left = $tenant->getAdapter();
        $right = $catalog->getAdapter();

        if ($left instanceof Pool && $right instanceof Pool) {
            return $left->getPool() === $right->getPool();
        }

        return $left->getHostname() === $right->getHostname();
    }

    /** @param array<int, true> $path */
    private function decorateRelationships(string $collectionId, Document $document, int $depth = 0, array $path = []): void
    {
        if ($depth >= Database::RELATION_MAX_DEPTH - 1) {
            return;
        }

        $path[\spl_object_id($document)] = true;
        $parentPublicId = $this->publicId($collectionId);
        $relationships = $this->getRelationships($collectionId);

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
                if ($relation instanceof Document && !isset($path[\spl_object_id($relation)])) {
                    $this->operations++;
                    $relation->setAttribute('$databaseId', $this->database->getId());
                    $relatedInternalId = $relation->getCollection();
                    $relation->setAttribute(
                        '$' . $this->context . 'Id',
                        $relatedInternalId !== ''
                            ? $this->publicId($relatedInternalId)
                            : $parentPublicId
                    );

                    $relatedCollectionId = $relationship->getAttribute('options', [])['relatedCollection'] ?? '';
                    if ($relatedCollectionId !== '') {
                        $this->decorateRelationships($relatedCollectionId, $relation, $depth + 1, $path);
                    }
                }
            }
        }
    }

    /**
     * @return array<Document>
     */
    private function getRelationships(string $collectionId, ?Document $collection = null): array
    {
        if (!isset($this->relationshipCache[$collectionId])) {
            if ($collection === null && $this->tenant !== null) {
                $collection = $this->tenant->silent(fn (): Document => $this->tenant->getCollection($collectionId));
            }
            $this->relationshipCache[$collectionId] = \array_filter(
                $collection?->getAttribute('attributes', []) ?? [],
                static fn (Document $attribute): bool => $attribute->getAttribute('type') === ColumnType::Relationship->value
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
