<?php

namespace Utopia\Database;

use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Mirroring\Filter;
use Utopia\Database\Validator\Authorization;

class Mirror extends Database
{
    protected Database $source;
    protected ?Database $destination;

    /**
     * Filters to apply to documents before writing to the destination database
     *
     * @var array<Filter>
     */
    protected array $writeFilters = [];

    /**
     * Callbacks to run when an error occurs on the destination database
     *
     * @var array<callable(string, \Throwable): void>
     */
    protected array $errorCallbacks = [];

    /**
     * Collections that should only be present in the source database
     */
    protected const SOURCE_ONLY_COLLECTIONS = [
        'upgrades',
    ];

    /**
     * @param Database $source
     * @param ?Database $destination
     * @param array<Filter> $filters
     */
    public function __construct(
        Database $source,
        ?Database $destination = null,
        array $filters = [],
    ) {
        parent::__construct(
            $source->getAdapter(),
            $source->getCache()
        );
        $this->source = $source;
        $this->destination = $destination;
        $this->writeFilters = $filters;
    }

    public function getSource(): Database
    {
        return $this->source;
    }

    public function getDestination(): ?Database
    {
        return $this->destination;
    }

    /**
     * @return array<Filter>
     */
    public function getWriteFilters(): array
    {
        return $this->writeFilters;
    }

    /**
     * @param callable(string, \Throwable): void $callback
     * @return void
     */
    public function onError(callable $callback): void
    {
        $this->errorCallbacks[] = $callback;
    }

    /**
     * @param string $method
     * @param array<mixed> $args
     * @return mixed
     */
    protected function delegate(string $method, array $args = []): mixed
    {
        $result = $this->source->{$method}(...$args);

        if ($this->destination === null) {
            return $result;
        }

        try {
            $result = $this->destination->{$method}(...$args);
        } catch (\Throwable $err) {
            $this->logError($method, $err);
        }

        return $result;
    }

    public function setDatabase(string $name): static
    {
        $this->delegate(__FUNCTION__, \func_get_args());

        return $this;
    }

    public function setNamespace(string $namespace): static
    {
        $this->delegate(__FUNCTION__, \func_get_args());

        return $this;
    }

    public function setSharedTables(bool $sharedTables): static
    {
        $this->delegate(__FUNCTION__, \func_get_args());

        return $this;
    }

    public function setTenant(int|string|null $tenant): static
    {
        $this->delegate(__FUNCTION__, \func_get_args());

        return $this;
    }

    public function setDropUnknownAttributes(bool $drop): static
    {
        $this->delegate(__FUNCTION__, \func_get_args());

        $this->dropUnknownAttributes = $drop;

        return $this;
    }

    public function setPreserveDates(bool $preserve): static
    {
        $this->delegate(__FUNCTION__, \func_get_args());

        $this->preserveDates = $preserve;

        return $this;
    }

    public function setPreserveSequence(bool $preserve): static
    {
        $this->delegate(__FUNCTION__, \func_get_args());

        $this->preserveSequence = $preserve;

        return $this;
    }

    public function enableValidation(): static
    {
        $this->delegate(__FUNCTION__);

        $this->validate = true;

        return $this;
    }

    public function disableValidation(): static
    {
        $this->delegate(__FUNCTION__);

        $this->validate = false;

        return $this;
    }

    public function on(string $event, string $name, ?callable $callback): static
    {
        $this->source->on($event, $name, $callback);

        return $this;
    }

    protected function trigger(string $event, mixed $args = null): void
    {
        $this->source->trigger($event, $args);
    }

    public function silent(callable $callback, ?array $listeners = null): mixed
    {
        return $this->source->silent($callback, $listeners);
    }

    public function withRequestTimestamp(?\DateTime $requestTimestamp, callable $callback): mixed
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function exists(?string $database = null, ?string $collection = null): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function create(?string $database = null): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function delete(?string $database = null): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function createCollection(string $id, array $attributes = [], array $indexes = [], ?array $permissions = null, bool $documentSecurity = true): Document
    {
        $result = $this->source->createCollection(
            $id,
            $attributes,
            $indexes,
            $permissions,
            $documentSecurity
        );

        if ($this->destination === null) {
            return $result;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $result = $filter->beforeCreateCollection(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $id,
                    collection: $result,
                );
            }

            $this->destination->createCollection(
                $id,
                $attributes,
                $indexes,
                $permissions,
                $documentSecurity
            );

            $this->silent(function () use ($id) {
                $this->createUpgrades();

                $this->source->createDocument('upgrades', new Document([
                    '$id' => $id,
                    'collectionId' => $id,
                    'status' => 'upgraded'
                ]));
            });
        } catch (\Throwable $err) {
            $this->logError('createCollection', $err);
        }
        return $result;
    }

    public function updateCollection(string $id, array $permissions, bool $documentSecurity): Document
    {
        $result = $this->source->updateCollection($id, $permissions, $documentSecurity);

        if ($this->destination === null) {
            return $result;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $result = $filter->beforeUpdateCollection(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $id,
                    collection: $result,
                );
            }

            $this->destination->updateCollection($id, $permissions, $documentSecurity);
        } catch (\Throwable $err) {
            $this->logError('updateCollection', $err);
        }

        return $result;
    }

    public function deleteCollection(string $id): bool
    {
        $result = $this->source->deleteCollection($id);

        if ($this->destination === null) {
            return $result;
        }

        try {
            $this->destination->deleteCollection($id);

            foreach ($this->writeFilters as $filter) {
                $filter->beforeDeleteCollection(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $id,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('deleteCollection', $err);
        }

        return $result;
    }

    public function createAttribute(string $collection, string $id, string $type, int $size, bool $required, $default = null, bool $signed = true, bool $array = false, ?string $format = null, array $formatOptions = [], array $filters = []): bool
    {
        $result = $this->source->createAttribute(
            $collection,
            $id,
            $type,
            $size,
            $required,
            $default,
            $signed,
            $array,
            $format,
            $formatOptions,
            $filters
        );

        if ($this->destination === null) {
            return $result;
        }

        try {
            $document = new Document([
                '$id' => $id,
                'type' => $type,
                'size' => $size,
                'required' => $required,
                'default' => $default,
                'signed' => $signed,
                'array' => $array,
                'format' => $format,
                'formatOptions' => $formatOptions,
                'filters' => $filters,
            ]);

            foreach ($this->writeFilters as $filter) {
                $document = $filter->beforeCreateAttribute(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    attributeId: $id,
                    attribute: $document,
                );
            }

            $result = $this->destination->createAttribute(
                $collection,
                $document->getId(),
                $document->getAttribute('type'),
                $document->getAttribute('size'),
                $document->getAttribute('required'),
                $document->getAttribute('default'),
                $document->getAttribute('signed'),
                $document->getAttribute('array'),
                $document->getAttribute('format'),
                $document->getAttribute('formatOptions'),
                $document->getAttribute('filters'),
            );
        } catch (\Throwable $err) {
            $this->logError('createAttribute', $err);
        }

        return $result;
    }

    public function createAttributes(string $collection, array $attributes): bool
    {
        $result = $this->source->createAttributes($collection, $attributes);

        if ($this->destination === null) {
            return $result;
        }

        try {
            foreach ($attributes as &$attribute) {
                foreach ($this->writeFilters as $filter) {
                    $document = $filter->beforeCreateAttribute(
                        source: $this->source,
                        destination: $this->destination,
                        collectionId: $collection,
                        attributeId: $attribute['$id'],
                        attribute: new Document($attribute),
                    );

                    $attribute = $document->getArrayCopy();
                }
            }

            $result = $this->destination->createAttributes(
                $collection,
                $attributes,
            );
        } catch (\Throwable $err) {
            $this->logError('createAttributes', $err);
        }

        return $result;
    }

    public function updateAttribute(string $collection, string $id, ?string $type = null, ?int $size = null, ?bool $required = null, mixed $default = null, ?bool $signed = null, ?bool $array = null, ?string $format = null, ?array $formatOptions = null, ?array $filters = null, ?string $newKey = null): Document
    {
        $document = $this->source->updateAttribute(
            $collection,
            $id,
            $type,
            $size,
            $required,
            $default,
            $signed,
            $array,
            $format,
            $formatOptions,
            $filters,
            $newKey,
        );

        if ($this->destination === null) {
            return $document;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $document = $filter->beforeUpdateAttribute(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    attributeId: $id,
                    attribute: $document,
                );
            }

            $this->destination->updateAttribute(
                $collection,
                $id,
                $document->getAttribute('type'),
                $document->getAttribute('size'),
                $document->getAttribute('required'),
                $document->getAttribute('default'),
                $document->getAttribute('signed'),
                $document->getAttribute('array'),
                $document->getAttribute('format'),
                $document->getAttribute('formatOptions'),
                $document->getAttribute('filters'),
                $newKey,
            );
        } catch (\Throwable $err) {
            $this->logError('updateAttribute', $err);
        }

        return $document;
    }

    public function deleteAttribute(string $collection, string $id): bool
    {
        $result = $this->source->deleteAttribute($collection, $id);

        if ($this->destination === null) {
            return $result;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $filter->beforeDeleteAttribute(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    attributeId: $id,
                );
            }

            $this->destination->deleteAttribute($collection, $id);
        } catch (\Throwable $err) {
            $this->logError('deleteAttribute', $err);
        }

        return $result;
    }

    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths = [], array $orders = [], int $ttl = 1): bool
    {
        $result = $this->source->createIndex($collection, $id, $type, $attributes, $lengths, $orders, $ttl);

        if ($this->destination === null) {
            return $result;
        }

        try {
            $document = new Document([
                '$id' => $id,
                'type' => $type,
                'attributes' => $attributes,
                'lengths' => $lengths,
                'orders' => $orders,
            ]);

            foreach ($this->writeFilters as $filter) {
                $document = $filter->beforeCreateIndex(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    indexId: $id,
                    index: $document,
                );
            }

            $result = $this->destination->createIndex(
                $collection,
                $document->getId(),
                $document->getAttribute('type'),
                $document->getAttribute('attributes'),
                $document->getAttribute('lengths'),
                $document->getAttribute('orders'),
                $document->getAttribute('ttl', 0)
            );
        } catch (\Throwable $err) {
            $this->logError('createIndex', $err);
        }

        return $result;
    }

    public function deleteIndex(string $collection, string $id): bool
    {
        $result = $this->source->deleteIndex($collection, $id);

        if ($this->destination === null) {
            return $result;
        }

        try {
            $this->destination->deleteIndex($collection, $id);

            foreach ($this->writeFilters as $filter) {
                $filter->beforeDeleteIndex(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    indexId: $id,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('deleteIndex', $err);
        }

        return $result;
    }

    public function createDocument(string $collection, Document $document): Document
    {
        $document = $this->source->createDocument($collection, $document);

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $document;
        }

        $upgrade = $this->silent(fn () => $this->getUpgradeStatus($collection));
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $document;
        }

        try {
            $clone = clone $document;

            foreach ($this->writeFilters as $filter) {
                $clone = $filter->beforeCreateDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    document: $clone,
                );
            }

            $this->destination->setPreserveDates(true);
            $document = $this->destination->createDocument($collection, $clone);
            $this->destination->setPreserveDates(false);

            foreach ($this->writeFilters as $filter) {
                $filter->afterCreateDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    document: $clone,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('createDocument', $err);
        }

        return $document;
    }

    public function createDocuments(
        string $collection,
        array $documents,
        int $batchSize = self::INSERT_BATCH_SIZE,
        ?callable $onNext = null,
        ?callable $onError = null,
    ): int {
        $modified = $this->skipDuplicates
            ? $this->source->skipDuplicates(
                fn () => $this->source->createDocuments($collection, $documents, $batchSize, $onNext, $onError)
            )
            : $this->source->createDocuments($collection, $documents, $batchSize, $onNext, $onError);

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $modified;
        }

        $upgrade = $this->silent(fn () => $this->getUpgradeStatus($collection));
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $modified;
        }

        // Forward every input to destination. "upgraded" status means the schema
        // is mirrored, not that every row is backfilled, so a row that is a
        // duplicate on source may not yet exist on destination. In skipDuplicates
        // mode the destination runs its own INSERT IGNORE and decides per-row.
        try {
            $clones = [];
            foreach ($documents as $document) {
                $clone = clone $document;
                foreach ($this->writeFilters as $filter) {
                    $clone = $filter->beforeCreateDocument(
                        source: $this->source,
                        destination: $this->destination,
                        collectionId: $collection,
                        document: $clone,
                    );
                }
                $clones[] = $clone;
            }

            if ($this->skipDuplicates) {
                $this->destination->skipDuplicates(
                    fn () => $this->destination->withPreserveDates(
                        fn () => $this->destination->createDocuments($collection, $clones, $batchSize)
                    )
                );
            } else {
                $this->destination->withPreserveDates(
                    fn () =>
                    $this->destination->createDocuments(
                        $collection,
                        $clones,
                        $batchSize,
                    )
                );
            }

            foreach ($clones as $clone) {
                foreach ($this->writeFilters as $filter) {
                    $filter->afterCreateDocument(
                        source: $this->source,
                        destination: $this->destination,
                        collectionId: $collection,
                        document: $clone,
                    );
                }
            }
        } catch (\Throwable $err) {
            $this->logError('createDocuments', $err);
        }

        return $modified;
    }

    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        $document = $this->source->updateDocument($collection, $id, $document);

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $document;
        }

        $upgrade = $this->silent(fn () => $this->getUpgradeStatus($collection));

        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $document;
        }

        try {
            $clone = clone $document;

            foreach ($this->writeFilters as $filter) {
                $clone = $filter->beforeUpdateDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    document: $clone,
                );
            }

            $this->destination->setPreserveDates(true);
            $this->destination->updateDocument($collection, $id, $clone);
            $this->destination->setPreserveDates(false);

            foreach ($this->writeFilters as $filter) {
                $filter->afterUpdateDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    document: $clone,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('updateDocument', $err);
        }

        return $document;
    }

    public function updateDocuments(
        string $collection,
        Document $updates,
        array $queries = [],
        int $batchSize = self::INSERT_BATCH_SIZE,
        ?callable $onNext = null,
        ?callable $onError = null,
    ): int {
        $modified = $this->source->updateDocuments(
            $collection,
            $updates,
            $queries,
            $batchSize,
            $onNext,
            $onError,
        );

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $modified;
        }

        $upgrade = $this->silent(fn () => $this->getUpgradeStatus($collection));
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $modified;
        }

        try {
            $clone = clone $updates;

            foreach ($this->writeFilters as $filter) {
                $clone = $filter->beforeUpdateDocuments(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    updates: $clone,
                    queries: $queries,
                );
            }

            $this->destination->withPreserveDates(
                fn () =>
                $this->destination->updateDocuments(
                    $collection,
                    $clone,
                    $queries,
                    $batchSize,
                )
            );

            foreach ($this->writeFilters as $filter) {
                $filter->afterUpdateDocuments(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    updates: $clone,
                    queries: $queries,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('updateDocuments', $err);
        }

        return $modified;
    }

    public function upsertDocuments(
        string $collection,
        array $documents,
        int $batchSize = Database::INSERT_BATCH_SIZE,
        ?callable $onNext = null,
        ?callable $onError = null,
    ): int {
        $modified = $this->source->upsertDocuments(
            $collection,
            $documents,
            $batchSize,
            $onNext,
            $onError,
        );

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $modified;
        }

        $upgrade = $this->silent(fn () => $this->getUpgradeStatus($collection));
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $modified;
        }

        try {
            $clones = [];

            foreach ($documents as $document) {
                $clone = clone $document;

                foreach ($this->writeFilters as $filter) {
                    $clone = $filter->beforeCreateOrUpdateDocument(
                        source: $this->source,
                        destination: $this->destination,
                        collectionId: $collection,
                        document: $clone,
                    );
                }

                $clones[] = $clone;
            }

            $this->destination->withPreserveDates(
                fn () =>
                $this->destination->upsertDocuments(
                    $collection,
                    $clones,
                    $batchSize,
                )
            );

            foreach ($clones as $clone) {
                foreach ($this->writeFilters as $filter) {
                    $filter->afterCreateOrUpdateDocument(
                        source: $this->source,
                        destination: $this->destination,
                        collectionId: $collection,
                        document: $clone,
                    );
                }
            }
        } catch (\Throwable $err) {
            $this->logError('upsertDocuments', $err);
        }

        return $modified;
    }

    public function deleteDocument(string $collection, string $id): bool
    {
        $result = $this->source->deleteDocument($collection, $id);

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $result;
        }

        $upgrade = $this->silent(fn () => $this->getUpgradeStatus($collection));
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $result;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $filter->beforeDeleteDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    documentId: $id,
                );
            }

            $this->destination->deleteDocument($collection, $id);

            foreach ($this->writeFilters as $filter) {
                $filter->afterDeleteDocument(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    documentId: $id,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('deleteDocument', $err);
        }

        return $result;
    }

    public function deleteDocuments(
        string $collection,
        array $queries = [],
        int $batchSize = self::DELETE_BATCH_SIZE,
        ?callable $onNext = null,
        ?callable $onError = null,
    ): int {
        $modified = $this->source->deleteDocuments(
            $collection,
            $queries,
            $batchSize,
            $onNext,
            $onError,
        );

        if (
            \in_array($collection, self::SOURCE_ONLY_COLLECTIONS)
            || $this->destination === null
        ) {
            return $modified;
        }

        $upgrade = $this->silent(fn () => $this->getUpgradeStatus($collection));
        if ($upgrade === null || $upgrade->getAttribute('status', '') !== 'upgraded') {
            return $modified;
        }

        try {
            foreach ($this->writeFilters as $filter) {
                $filter->beforeDeleteDocuments(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    queries: $queries,
                );
            }

            $this->destination->deleteDocuments(
                $collection,
                $queries,
                $batchSize,
            );

            foreach ($this->writeFilters as $filter) {
                $filter->afterDeleteDocuments(
                    source: $this->source,
                    destination: $this->destination,
                    collectionId: $collection,
                    queries: $queries,
                );
            }
        } catch (\Throwable $err) {
            $this->logError('deleteDocuments', $err);
        }

        return $modified;
    }

    public function updateAttributeRequired(string $collection, string $id, bool $required): Document
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function updateAttributeFormat(string $collection, string $id, string $format): Document
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function updateAttributeFormatOptions(string $collection, string $id, array $formatOptions): Document
    {
        return $this->delegate(__FUNCTION__, [$collection, $id, $formatOptions]);
    }

    public function updateAttributeFilters(string $collection, string $id, array $filters): Document
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function updateAttributeDefault(string $collection, string $id, mixed $default = null): Document
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function renameAttribute(string $collection, string $old, string $new): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function createRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay = false,
        ?string $id = null,
        ?string $twoWayKey = null,
        string $onDelete = Database::RELATION_MUTATE_RESTRICT
    ): bool {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function updateRelationship(
        string $collection,
        string $id,
        ?string $newKey = null,
        ?string $newTwoWayKey = null,
        ?bool $twoWay = null,
        ?string $onDelete = null
    ): bool {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function deleteRelationship(string $collection, string $id): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }


    public function renameIndex(string $collection, string $old, string $new): bool
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function increaseDocumentAttribute(string $collection, string $id, string $attribute, int|float $value = 1, int|float|null $max = null): Document
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    public function decreaseDocumentAttribute(string $collection, string $id, string $attribute, int|float $value = 1, int|float|null $min = null): Document
    {
        return $this->delegate(__FUNCTION__, \func_get_args());
    }

    /**
     * @throws Limit
     * @throws DuplicateException
     * @throws Exception
     */
    public function createUpgrades(): void
    {
        $collection = $this->source->getCollection('upgrades');

        if (!$collection->isEmpty()) {
            return;
        }

        $this->source->createCollection(
            id: 'upgrades',
            attributes: [
                new Document([
                    '$id' => ID::custom('collectionId'),
                    'type' => Database::VAR_STRING,
                    'size' => Database::LENGTH_KEY,
                    'required' => true,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                    'default' => null,
                    'format' => ''
                ]),
                new Document([
                    '$id' => ID::custom('status'),
                    'type' => Database::VAR_STRING,
                    'size' => Database::LENGTH_KEY,
                    'required' => false,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                    'default' => null,
                    'format' => ''
                ]),
            ],
            indexes: [
                new Document([
                    '$id' => ID::custom('_unique_collection'),
                    'type' => Database::INDEX_UNIQUE,
                    'attributes' => ['collectionId'],
                    'lengths' => [Database::LENGTH_KEY],
                    'orders' => [],
                ]),
                new Document([
                    '$id' => ID::custom('_status_index'),
                    'type' => Database::INDEX_KEY,
                    'attributes' => ['status'],
                    'lengths' => [Database::LENGTH_KEY],
                    'orders' => [Database::ORDER_ASC],
                ]),
            ],
        );
    }

    /**
     * @throws Exception
     */
    protected function getUpgradeStatus(string $collection): ?Document
    {
        if ($collection === 'upgrades' || $collection === Database::METADATA) {
            return new Document();
        }

        return $this->getSource()->getAuthorization()->skip(function () use ($collection) {
            try {
                return $this->source->getDocument('upgrades', $collection);
            } catch (\Throwable) {
                return;
            }
        });
    }

    protected function logError(string $action, \Throwable $err): void
    {
        foreach ($this->errorCallbacks as $callback) {
            $callback($action, $err);
        }
    }

    public function setAuthorization(Authorization $authorization): self
    {

        parent::setAuthorization($authorization);

        if (isset($this->source)) {
            $this->source->setAuthorization($authorization);
        }
        if (isset($this->destination)) {
            $this->destination->setAuthorization($authorization);
        }

        return $this;
    }

    /**
     * Set custom document class for a collection
     *
     * @param string $collection Collection ID
     * @param class-string<Document> $className Fully qualified class name that extends Document
     * @return static
     */
    public function setDocumentType(string $collection, string $className): static
    {
        $this->delegate(__FUNCTION__, \func_get_args());
        $this->documentTypes[$collection] = $className;
        return $this;
    }

    /**
     * Clear document type mapping for a collection
     *
     * @param string $collection Collection ID
     * @return static
     */
    public function clearDocumentType(string $collection): static
    {
        $this->delegate(__FUNCTION__, \func_get_args());
        unset($this->documentTypes[$collection]);

        return $this;
    }

    /**
     * Clear all document type mappings
     *
     * @return static
     */
    public function clearAllDocumentTypes(): static
    {
        $this->delegate(__FUNCTION__);
        $this->documentTypes = [];

        return $this;
    }

}
