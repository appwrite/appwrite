<?php

namespace Utopia\Database;

use Exception;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Validator\Authorization;

abstract class Adapter
{
    protected string $database = '';
    protected string $hostname = '';

    protected string $namespace = '';

    protected bool $sharedTables = false;

    protected int|string|null $tenant = null;

    protected bool $tenantPerDocument = false;

    protected int $timeout = 0;

    protected int $inTransaction = 0;

    protected bool $alterLocks = false;

    protected bool $skipDuplicates = false;

    /**
     * @var array<string, mixed>
     */
    protected array $debug = [];

    /**
     * @var array<string, array<callable>>
     */
    protected array $transformations = [
        '*' => [],
    ];

    /**
     * @var array<string, mixed>
     */
    protected array $metadata = [];

    /**
     * @var Authorization
     */
    protected Authorization $authorization;

    /**
     * @param Authorization $authorization
     *
     * @return $this
     */
    public function setAuthorization(Authorization $authorization): self
    {
        $this->authorization = $authorization;

        return $this;
    }

    public function getAuthorization(): Authorization
    {
        return $this->authorization;
    }
    /**
     * @param string $key
     * @param mixed $value
     *
     * @return $this
     */
    public function setDebug(string $key, mixed $value): static
    {
        $this->debug[$key] = $value;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDebug(): array
    {
        return $this->debug;
    }

    /**
     * @return static
     */
    public function resetDebug(): static
    {
        $this->debug = [];

        return $this;
    }

    /**
     * Set Namespace.
     *
     * Set namespace to divide different scope of data sets
     *
     * @param string $namespace
     *
     * @return $this
     * @throws DatabaseException
     *
     */
    public function setNamespace(string $namespace): static
    {
        $this->namespace = $this->filter($namespace);

        return $this;
    }

    /**
     * Get Namespace.
     *
     * Get namespace of current set scope
     *
     * @return string
     *
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Set Hostname.
     *
     * @param string $hostname
     * @return $this
     */
    public function setHostname(string $hostname): static
    {
        $this->hostname = $hostname;

        return $this;
    }

    /**
     * Get Hostname.
     *
     * @return string
     */
    public function getHostname(): string
    {
        return $this->hostname;
    }

    /**
     * Set Database.
     *
     * Set database to use for current scope
     *
     * @param string $name
     *
     * @return bool
     * @throws DatabaseException
     */
    public function setDatabase(string $name): bool
    {
        $this->database = $this->filter($name);

        return true;
    }

    /**
     * Get Database.
     *
     * Get Database from current scope
     *
     * @return string
     *
     */
    public function getDatabase(): string
    {
        return $this->database;
    }

    /**
     * Set Shared Tables.
     *
     * Set whether to share tables between tenants
     *
     * @param bool $sharedTables
     *
     * @return bool
     */
    public function setSharedTables(bool $sharedTables): bool
    {
        $this->sharedTables = $sharedTables;

        return true;
    }

    /**
     * Get Share Tables.
     *
     * Get whether to share tables between tenants
     *
     * @return bool
     */
    public function getSharedTables(): bool
    {
        return $this->sharedTables;
    }

    /**
     * Set Tenant.
     *
     * Set tenant to use if tables are shared
     *
     * @param int|string|null $tenant
     *
     * @return bool
     */
    public function setTenant(int|string|null $tenant): bool
    {
        $this->tenant = $tenant;

        return true;
    }

    /**
     * Get Tenant.
     *
     * Get tenant to use for shared tables
     *
     * @return int|string|null
     */
    public function getTenant(): int|string|null
    {
        return $this->tenant;
    }

    /**
     * Set Tenant Per Document.
     *
     * Set whether to use a different tenant for each document
     *
     * @param bool $tenantPerDocument
     *
     * @return bool
     */
    public function setTenantPerDocument(bool $tenantPerDocument): bool
    {
        $this->tenantPerDocument = $tenantPerDocument;

        return true;
    }

    /**
     * Get Tenant Per Document.
     *
     * Get whether to use a different tenant for each document
     *
     * @return bool
     */
    public function getTenantPerDocument(): bool
    {
        return $this->tenantPerDocument;
    }

    /**
     * Set metadata for query comments
     *
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setMetadata(string $key, mixed $value): static
    {
        $this->metadata[$key] = $value;

        $output = '';
        foreach ($this->metadata as $key => $value) {
            $output .= "/* {$key}: {$value} */\n";
        }

        $this->before(Database::EVENT_ALL, 'metadata', function ($query) use ($output) {
            return $output . $query;
        });

        return $this;
    }

    /**
     * Get metadata
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Clear existing metadata
     *
     * @return $this
     */
    public function resetMetadata(): static
    {
        $this->metadata = [];

        return $this;
    }

    /**
     * Set a global timeout for database queries in milliseconds.
     *
     * This function allows you to set a maximum execution time for all database
     * queries executed using the library, or a specific event specified by the
     * event parameter. Once this timeout is set, any database query that takes
     * longer than the specified time will be automatically terminated by the library,
     * and an appropriate error or exception will be raised to handle the timeout condition.
     *
     * @param int $milliseconds The timeout value in milliseconds for database queries.
     * @param string $event     The event the timeout should fire for
     * @return void
     *
     * @throws Exception The provided timeout value must be greater than or equal to 0.
     */
    abstract public function setTimeout(int $milliseconds, string $event = Database::EVENT_ALL): void;

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    /**
     * Clears a global timeout for database queries.
     *
     * @param string $event
     * @return void
     */
    public function clearTimeout(string $event): void
    {
        // Clear existing callback
        $this->before($event, 'timeout');

        // Adapters that apply the timeout from this property on every statement
        // (e.g. Postgres SET statement_timeout) would otherwise keep enforcing a
        // cleared timeout on all subsequent queries.
        $this->timeout = 0;
    }

    /**
     * Clears every timeout this adapter carries, for any event.
     *
     * A pooled connection outlives the handle that configured it, so the handle
     * that takes it next has to be able to reset it without knowing which
     * events the previous one set a timeout for.
     *
     * @return void
     */
    public function clearTimeouts(): void
    {
        foreach (\array_keys($this->transformations) as $event) {
            $this->clearTimeout($event);
        }

        $this->timeout = 0;
    }

    /**
     * Start a new transaction.
     *
     * If a transaction is already active, this will only increment the transaction count and return true.
     *
     * @return bool
     * @throws DatabaseException
     */
    abstract public function startTransaction(): bool;

    /**
     * Commit a transaction.
     *
     * If no transaction is active, this will be a no-op and will return false.
     * If there is more than one active transaction, this decrement the transaction count and return true.
     * If the transaction count is 1, it will be commited, the transaction count will be reset to 0, and return true.
     *
     * @return bool
     * @throws DatabaseException
     */
    abstract public function commitTransaction(): bool;

    /**
     * Rollback a transaction.
     *
     * If no transaction is active, this will be a no-op and will return false.
     * If 1 or more transactions are active, this will roll back all transactions, reset the count to 0, and return true.
     *
     * @return bool
     * @throws DatabaseException
     */
    abstract public function rollbackTransaction(): bool;

    /**
     * Check if a transaction is active.
     *
     * @return bool
     */
    public function inTransaction(): bool
    {
        return $this->inTransaction > 0;
    }

    /**
     * Run a callback with skipDuplicates enabled.
     * Duplicate key errors during createDocuments() will be silently skipped
     * instead of thrown. Nestable — saves and restores previous state.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function skipDuplicates(callable $callback): mixed
    {
        $previous = $this->skipDuplicates;
        $this->skipDuplicates = true;

        try {
            return $callback();
        } finally {
            $this->skipDuplicates = $previous;
        }
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \Throwable
     */
    public function withTransaction(callable $callback): mixed
    {
        $sleep = 50_000; // 50 milliseconds
        $retries = 2;

        for ($attempts = 0; $attempts <= $retries; $attempts++) {
            try {
                $this->startTransaction();
                $result = $callback();
                $this->commitTransaction();
                return $result;
            } catch (\Throwable $action) {
                $rollback = null;
                try {
                    $this->rollbackTransaction();
                } catch (\Throwable $rollbackError) {
                    // Not every adapter resets the depth counter when its
                    // rollback throws (e.g. Redis), so reset it here to avoid
                    // leaking transaction state onto the reused connection.
                    $rollback = $rollbackError;
                    $this->inTransaction = 0;
                }

                if (
                    $action instanceof DuplicateException ||
                    $action instanceof RestrictedException ||
                    $action instanceof AuthorizationException ||
                    $action instanceof RelationshipException ||
                    $action instanceof ConflictException ||
                    $action instanceof LimitException ||
                    $action instanceof TimeoutException
                ) {
                    throw $action;
                }

                if ($attempts < $retries) {
                    \usleep($sleep * ($attempts + 1));
                    continue;
                }

                throw $rollback ?? $action;
            }
        }

        throw new TransactionException('Failed to execute transaction');
    }

    /**
     * Apply a transformation to a query before an event occurs
     *
     * @param string $event
     * @param string $name
     * @param ?callable $callback
     * @return static
     */
    public function before(string $event, string $name = '', ?callable $callback = null): static
    {
        if (!isset($this->transformations[$event])) {
            $this->transformations[$event] = [];
        }

        if (\is_null($callback)) {
            unset($this->transformations[$event][$name]);
        } else {
            $this->transformations[$event][$name] = $callback;
        }

        return $this;
    }

    protected function trigger(string $event, mixed $query): mixed
    {
        foreach ($this->transformations[Database::EVENT_ALL] as $callback) {
            $query = $callback($query);
        }
        foreach (($this->transformations[$event] ?? []) as $callback) {
            $query = $callback($query);
        }

        return $query;
    }

    /**
     * Quote a string
     *
     * @param string $string
     * @return string
     */
    abstract protected function quote(string $string): string;

    /**
     * Ping Database
     *
     * @return bool
     */
    abstract public function ping(): bool;

    /**
     * Reconnect Database
     */
    abstract public function reconnect(): void;

    /**
     * Create Database
     *
     * @param string $name
     *
     * @return bool
     */
    abstract public function create(string $name): bool;

    /**
     * Check if database exists
     * Optionally check if collection exists in database
     *
     * @param string $database database name
     * @param string|null $collection (optional) collection name
     *
     * @return bool
     */
    abstract public function exists(string $database, ?string $collection = null): bool;

    /**
     * List Databases
     *
     * @return array<Document>
     */
    abstract public function list(): array;

    /**
     * Delete Database
     *
     * @param string $name
     *
     * @return bool
     */
    abstract public function delete(string $name): bool;

    /**
     * Create Collection
     *
     * @param string $name
     * @param array<Document> $attributes (optional)
     * @param array<Document> $indexes (optional)
     * @return bool
     */
    abstract public function createCollection(string $name, array $attributes = [], array $indexes = []): bool;

    /**
     * Delete Collection
     *
     * @param string $id
     *
     * @return bool
     */
    abstract public function deleteCollection(string $id): bool;

    /**
     * Analyze a collection updating its metadata on the database engine
     *
     * @param string $collection
     * @return bool
     */
    abstract public function analyzeCollection(string $collection): bool;

    /**
     * Create Attribute
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @param bool $array
     * @return bool
     * @throws TimeoutException
     * @throws DuplicateException
     */
    abstract public function createAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, bool $required = false): bool;

    /**
     * Create Attributes
     *
     * @param string $collection
     * @param array<array<string, mixed>> $attributes
     * @return bool
     * @throws TimeoutException
     * @throws DuplicateException
     */
    abstract public function createAttributes(string $collection, array $attributes): bool;

    /**
     * Update Attribute
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @param bool $array
     * @param string|null $newKey
     * @param bool $required
     *
     * @return bool
     */
    abstract public function updateAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, ?string $newKey = null, bool $required = false): bool;

    /**
     * Delete Attribute
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    abstract public function deleteAttribute(string $collection, string $id): bool;

    /**
     * Rename Attribute
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     * @return bool
     */
    abstract public function renameAttribute(string $collection, string $old, string $new): bool;

    /**
     * @param string $collection
     * @param string $relatedCollection
     * @param string $type
     * @param bool $twoWay
     * @param string $id
     * @param string $twoWayKey
     * @return bool
     */
    abstract public function createRelationship(string $collection, string $relatedCollection, string $type, bool $twoWay = false, string $id = '', string $twoWayKey = ''): bool;

    /**
     * Update Relationship
     *
     * @param string $collection
     * @param string $relatedCollection
     * @param string $type
     * @param bool $twoWay
     * @param string $key
     * @param string $twoWayKey
     * @param string $side
     * @param string|null $newKey
     * @param string|null $newTwoWayKey
     * @return bool
     */
    abstract public function updateRelationship(string $collection, string $relatedCollection, string $type, bool $twoWay, string $key, string $twoWayKey, string $side, ?string $newKey = null, ?string $newTwoWayKey = null): bool;

    /**
     * Delete Relationship
     *
     * @param string $collection
     * @param string $relatedCollection
     * @param string $type
     * @param bool $twoWay
     * @param string $key
     * @param string $twoWayKey
     * @param string $side
     * @return bool
     */
    abstract public function deleteRelationship(string $collection, string $relatedCollection, string $type, bool $twoWay, string $key, string $twoWayKey, string $side): bool;

    /**
     * Rename Index
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     * @return bool
     */
    abstract public function renameIndex(string $collection, string $old, string $new): bool;

    /**
     * Create Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array<string> $attributes
     * @param array<int> $lengths
     * @param array<string> $orders
     * @param array<string,string> $indexAttributeTypes
     * @param array<string, mixed> $collation
     * @param int $ttl
     *
     * @return bool
     */
    abstract public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths, array $orders, array $indexAttributeTypes = [], array $collation = [], int $ttl = 1): bool;

    /**
     * Delete Index
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    abstract public function deleteIndex(string $collection, string $id): bool;

    /**
     * Get Document
     *
     * @param Document $collection
     * @param string $id
     * @param array<Query> $queries
     * @param bool $forUpdate
     * @return Document
     */
    abstract public function getDocument(Document $collection, string $id, array $queries = [], bool $forUpdate = false): Document;

    /**
     * Create Document
     *
     * @param Document $collection
     * @param Document $document
     *
     * @return Document
     */
    abstract public function createDocument(Document $collection, Document $document): Document;

    /**
     * Create Documents in batches
     *
     * @param Document $collection
     * @param array<Document> $documents
     *
     * @return array<Document>
     *
     * @throws DatabaseException
     */
    abstract public function createDocuments(Document $collection, array $documents): array;

    /**
     * Update Document
     *
     * @param Document $collection
     * @param string $id
     * @param Document $document
     * @param bool $skipPermissions
     *
     * @return Document
     */
    abstract public function updateDocument(Document $collection, string $id, Document $document, bool $skipPermissions): Document;

    /**
     * Update documents
     *
     * Updates all documents which match the given query.
     *
     * @param Document $collection
     * @param Document $updates
     * @param array<Document> $documents
     *
     * @return int
     *
     * @throws DatabaseException
     */
    abstract public function updateDocuments(Document $collection, Document $updates, array $documents): int;

    /**
     * Create documents if they do not exist, otherwise update them.
     *
     * If attribute is not empty, only the specified attribute will be increased, by the new value in each document.
     *
     * @param Document $collection
     * @param string $attribute
     * @param array<Change> $changes
     * @return array<Document>
     */
    abstract public function upsertDocuments(
        Document $collection,
        string $attribute,
        array $changes
    ): array;

    /**
     * @param string $collection
     * @param array<Document> $documents
     * @return array<Document>
     */
    abstract public function getSequences(string $collection, array $documents): array;

    /**
     * Delete Document
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     */
    abstract public function deleteDocument(string $collection, string $id): bool;

    /**
     * Delete Documents
     *
     * @param string $collection
     * @param array<string> $sequences
     * @param array<string> $permissionIds
     *
     * @return int
     */
    abstract public function deleteDocuments(string $collection, array $sequences, array $permissionIds): int;

    /**
     * Find Documents
     *
     * Find data sets using chosen queries
     *
     * @param Document $collection
     * @param array<Query> $queries
     * @param int|null $limit
     * @param int|null $offset
     * @param array<string> $orderAttributes
     * @param array<string> $orderTypes
     * @param array<string, mixed> $cursor
     * @param string $cursorDirection
     * @param string $forPermission
     * @return array<Document>
     */
    abstract public function find(Document $collection, array $queries = [], ?int $limit = 25, ?int $offset = null, array $orderAttributes = [], array $orderTypes = [], array $cursor = [], string $cursorDirection = Database::CURSOR_AFTER, string $forPermission = Database::PERMISSION_READ): array;

    /**
     * Sum an attribute
     *
     * @param Document $collection
     * @param string $attribute
     * @param array<Query> $queries
     * @param int|null $max
     *
     * @return int|float
     */
    abstract public function sum(Document $collection, string $attribute, array $queries = [], ?int $max = null): float|int;

    /**
     * Count Documents
     *
     * @param Document $collection
     * @param array<Query> $queries
     * @param int|null $max
     *
     * @return int
     */
    abstract public function count(Document $collection, array $queries = [], ?int $max = null): int;

    /**
     * Get Collection Size of the raw data
     *
     * @param string $collection
     * @return int
     * @throws DatabaseException
     */
    abstract public function getSizeOfCollection(string $collection): int;

    /**
     * Get Collection Size on the disk
     *
     * @param string $collection
     * @return int
     * @throws DatabaseException
     */
    abstract public function getSizeOfCollectionOnDisk(string $collection): int;

    /**
     * Get max STRING limit
     *
     * @return int
     */
    abstract public function getLimitForString(): int;

    /**
     * Get max INT limit
     *
     * @return int
     */
    abstract public function getLimitForInt(): int;

    /**
     * Get max BIGINT limit
     *
     * @return int
     */
    abstract public function getLimitForBigInt(): int;

    /**
     * Get maximum attributes limit.
     *
     * @return int
     */
    abstract public function getLimitForAttributes(): int;

    /**
     * Get maximum index limit.
     *
     * @return int
     */
    abstract public function getLimitForIndexes(): int;

    /**
     * @return int
     */
    abstract public function getMaxIndexLength(): int;

    /**
     * Get the maximum VARCHAR length for this adapter
     *
     * @return int
     */
    abstract public function getMaxVarcharLength(): int;

    /**
     * Get the maximum UID length for this adapter
     *
     * @return int
     */
    abstract public function getMaxUIDLength(): int;

    /**
     * Get the minimum supported DateTime value
     *
     * @return \DateTime
     */
    abstract public function getMinDateTime(): \DateTime;

    /**
     * Get the primitive type of the primary key type for this adapter
     *
     * @return string
     */
    abstract public function getIdAttributeType(): string;

    /**
     * Get the maximum supported DateTime value
     *
     * @return \DateTime
     */
    public function getMaxDateTime(): \DateTime
    {
        return new \DateTime('9999-12-31 23:59:59');
    }

    /**
     * Is schemas supported?
     *
     * @return bool
     */
    abstract public function getSupportForSchemas(): bool;

    /**
     * Are attributes supported?
     *
     * @return bool
     */
    abstract public function getSupportForAttributes(): bool;

    /**
     * Are schema attributes supported?
     *
     * @return bool
     */
    abstract public function getSupportForSchemaAttributes(): bool;

    /**
     * Are schema indexes supported?
     *
     * @return bool
     */
    abstract public function getSupportForSchemaIndexes(): bool;

    /**
     * Is index supported?
     *
     * @return bool
     */
    abstract public function getSupportForIndex(): bool;

    /**
     * Is indexing array supported?
     *
     * @return bool
     */
    abstract public function getSupportForIndexArray(): bool;

    /**
     * Is cast index as array supported?
     *
     * @return bool
     */
    abstract public function getSupportForCastIndexArray(): bool;

    /**
     * Is unique index supported?
     *
     * @return bool
     */
    abstract public function getSupportForUniqueIndex(): bool;

    /**
     * Is fulltext index supported?
     *
     * @return bool
     */
    abstract public function getSupportForFulltextIndex(): bool;

    /**
     * Is fulltext wildcard supported?
     *
     * @return bool
     */
    abstract public function getSupportForFulltextWildcardIndex(): bool;


    /**
     * Does the adapter handle casting?
     *
     * @return bool
     */
    abstract public function getSupportForCasting(): bool;

    /**
     * Does the adapter handle array Contains?
     *
     * @return bool
     */
    abstract public function getSupportForQueryContains(): bool;

    /**
     * Are timeouts supported?
     *
     * @return bool
     */
    abstract public function getSupportForTimeouts(): bool;

    /**
     * Are relationships supported?
     *
     * @return bool
     */
    abstract public function getSupportForRelationships(): bool;

    abstract public function getSupportForUpdateLock(): bool;

    /**
     * Are batch operations supported?
     *
     * @return bool
     */
    abstract public function getSupportForBatchOperations(): bool;

    /**
     * Is attribute resizing supported?
     *
     * @return bool
     */
    abstract public function getSupportForAttributeResizing(): bool;

    /**
     * Is get connection id supported?
     *
     * @return bool
     */
    abstract public function getSupportForGetConnectionId(): bool;

    /**
     * Is upserting supported?
     *
     * @return bool
     */
    abstract public function getSupportForUpserts(): bool;

    /**
     * Is upsert via arbitrary unique indexes supported?
     *
     * @return bool
     */
    abstract public function getSupportForUpsertOnUniqueIndex(): bool;

    /**
     * Is vector type supported?
     *
     * @return bool
     */
    abstract public function getSupportForVectors(): bool;

    /**
     * Is Cache Fallback supported?
     *
     * @return bool
     */
    abstract public function getSupportForCacheSkipOnFailure(): bool;

    /**
     * @return bool
     */
    abstract public function getSupportForCaching(): bool;

    /**
     * Is reconnection supported?
     *
     * @return bool
     */
    abstract public function getSupportForReconnection(): bool;

    /**
     * Is hostname supported?
     *
     * @return bool
     */
    abstract public function getSupportForHostname(): bool;

    /**
     * Is creating multiple attributes in a single query supported?
     *
     * @return bool
     */
    abstract public function getSupportForBatchCreateAttributes(): bool;

    /**
     * Is spatial attributes supported?
     *
     * @return bool
     */
    abstract public function getSupportForSpatialAttributes(): bool;

    /**
     * Are object (JSON) attributes supported?
     *
     * @return bool
     */
    abstract public function getSupportForObject(): bool;

    /**
     * Are object (JSON) indexes supported?
     *
     * @return bool
     */
    abstract public function getSupportForObjectIndexes(): bool;

    /**
     * Does the adapter support null values in spatial indexes?
     *
     * @return bool
     */
    abstract public function getSupportForSpatialIndexNull(): bool;

    /**
     * Does the adapter support operators?
     *
     * @return bool
     */
    abstract public function getSupportForOperators(): bool;

    /**
     * Adapter supports optional spatial attributes with existing rows.
     *
     * @return bool
     */
    abstract public function getSupportForOptionalSpatialAttributeWithExistingRows(): bool;

    /**
     * Does the adapter support order attribute in spatial indexes?
     *
     * @return bool
     */
    abstract public function getSupportForSpatialIndexOrder(): bool;

    /**
     * Does the adapter support spatial axis order specification?
     *
     * @return bool
     */
    abstract public function getSupportForSpatialAxisOrder(): bool;

    /**
     * Does the adapter includes boundary during spatial contains?
     *
     * @return bool
     */
    abstract public function getSupportForBoundaryInclusiveContains(): bool;

    /**
     * Does the adapter support calculating distance(in meters) between multidimension geometry(line, polygon,etc)?
     *
     * @return bool
     */
    abstract public function getSupportForDistanceBetweenMultiDimensionGeometryInMeters(): bool;

    /**
     * Does the adapter support multiple fulltext indexes?
     *
     * @return bool
     */
    abstract public function getSupportForMultipleFulltextIndexes(): bool;


    /**
     * Does the adapter support identical indexes?
     *
     * @return bool
     */
    abstract public function getSupportForIdenticalIndexes(): bool;

    /**
     * Does the adapter support random order by?
     *
     * @return bool
     */
    abstract public function getSupportForOrderRandom(): bool;

    /**
     * Get current attribute count from collection document
     *
     * @param Document $collection
     * @return int
     */
    abstract public function getCountOfAttributes(Document $collection): int;

    /**
     * Get current index count from collection document
     *
     * @param Document $collection
     * @return int
     */
    abstract public function getCountOfIndexes(Document $collection): int;

    /**
     * Returns number of attributes used by default.
     *
     * @return int
     */
    abstract public function getCountOfDefaultAttributes(): int;

    /**
     * Returns number of indexes used by default.
     *
     * @return int
     */
    abstract public function getCountOfDefaultIndexes(): int;

    /**
     * Get maximum width, in bytes, allowed for a SQL row
     * Return 0 when no restrictions apply
     *
     * @return int
     */
    abstract public function getDocumentSizeLimit(): int;

    /**
     * Estimate maximum number of bytes required to store a document in $collection.
     * Byte requirement varies based on column type and size.
     * Needed to satisfy MariaDB/MySQL row width limit.
     * Return 0 when no restrictions apply to row width
     *
     * @param Document $collection
     * @return int
     */
    abstract public function getAttributeWidth(Document $collection): int;

    /**
     * Get list of keywords that cannot be used
     *
     * @return array<string>
     */
    abstract public function getKeywords(): array;

    /**
     * Get an attribute projection given a list of selected attributes
     *
     * @param array<string> $selections
     * @param string $prefix
     * @return mixed
     */
    abstract protected function getAttributeProjection(array $selections, string $prefix): mixed;

    /**
     * Get all selected attributes from queries
     *
     * @param Query[] $queries
     * @return string[]
     */
    protected function getAttributeSelections(array $queries): array
    {
        $selections = [];

        foreach ($queries as $query) {
            switch ($query->getMethod()) {
                case Query::TYPE_SELECT:
                    foreach ($query->getValues() as $value) {
                        $selections[] = $value;
                    }
                    break;
            }
        }

        return $selections;
    }

    /**
     * Filter Keys
     *
     * @param string $value
     * @return string
     * @throws DatabaseException
     */
    public function filter(string $value): string
    {
        $value = \preg_replace("/[^A-Za-z0-9_\-]/", '', $value);

        if (\is_null($value)) {
            throw new DatabaseException('Failed to filter key');
        }

        return $value;
    }

    protected function escapeWildcards(string $value): string
    {
        $wildcards = [
            '%',
            '_',
            '[',
            ']',
            '^',
            '-',
            '.',
            '*',
            '+',
            '?',
            '(',
            ')',
            '{',
            '}',
            '|'
        ];

        foreach ($wildcards as $wildcard) {
            $value = \str_replace($wildcard, "\\$wildcard", $value);
        }

        return $value;
    }

    /**
     * Increase or decrease attribute value
     *
     * @param string $collection
     * @param string $id
     * @param string $attribute
     * @param int|float $value
     * @param string $updatedAt
     * @param int|float|null $min
     * @param int|float|null $max
     * @return bool
     * @throws Exception
     */
    abstract public function increaseDocumentAttribute(
        string $collection,
        string $id,
        string $attribute,
        int|float $value,
        string $updatedAt,
        int|float|null $min = null,
        int|float|null $max = null
    ): bool;

    /**
     * Returns the connection ID identifier
     *
     * @return string
     */
    abstract public function getConnectionId(): string;

    /**
     * Get List of internal index keys names
     *
     * @return array<string>
     */
    abstract public function getInternalIndexesKeys(): array;

    /**
     * Get Schema Attributes
     *
     * @param string $collection
     * @return array<Document>
     * @throws DatabaseException
     */
    abstract public function getSchemaAttributes(string $collection): array;

    /**
     * Get Schema Indexes
     *
     * Returns physical index definitions from the database schema.
     *
     * @param string $collection
     * @return array<Document>
     * @throws DatabaseException
     */
    abstract public function getSchemaIndexes(string $collection): array;

    /**
     * Get the expected column type for a given attribute type.
     *
     * Returns the database-native column type string (e.g. "VARCHAR(255)", "BIGINT")
     * that would be used when creating a column for the given attribute parameters.
     * Returns an empty string if the adapter does not support this operation.
     *
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @param bool $array
     * @param bool $required
     * @return string
     * @throws \Utopia\Database\Exception For unknown types on adapters that support column-type resolution.
     */
    public function getColumnType(string $type, int $size, bool $signed = true, bool $array = false, bool $required = false): string
    {
        return '';
    }

    /**
     * Get the query to check for tenant when in shared tables mode
     *
     * @param string $collection   The collection being queried
     * @param string $alias  The alias of the parent collection if in a subquery
     * @return string
     */
    abstract public function getTenantQuery(string $collection, string $alias = ''): string;

    /**
     * @param mixed $stmt
     * @return bool
     */
    abstract protected function execute(mixed $stmt): bool;

    /**
     * Decode a WKB or textual POINT into [x, y]
     *
     * @param string $wkb
     * @return float[] Array with two elements: [x, y]
     */
    abstract public function decodePoint(string $wkb): array;

    /**
     * Decode a WKB or textual LINESTRING into [[x1, y1], [x2, y2], ...]
     *
     * @param string $wkb
     * @return float[][] Array of points, each as [x, y]
     */
    abstract public function decodeLinestring(string $wkb): array;

    /**
     * Decode a WKB or textual POLYGON into [[[x1, y1], [x2, y2], ...], ...]
     *
     * @param string $wkb
     * @return float[][][] Array of rings, each ring is an array of points [x, y]
     */
    abstract public function decodePolygon(string $wkb): array;

    public function getSupportForUnsignedBigInt(): bool
    {
        return false;
    }

    /**
        * Returns the document after casting
        * @param Document $collection
        * @param Document $document
        * @return Document
        */
    abstract public function castingBefore(Document $collection, Document $document): Document;

    /**
     * Returns the document after casting
     * @param Document $collection
     * @param Document $document
     * @return Document
     */
    abstract public function castingAfter(Document $collection, Document $document): Document;

    /**
     * Is internal casting supported?
     *
     * @return bool
     */
    abstract public function getSupportForInternalCasting(): bool;

    /**
     * Is UTC casting supported?
     *
     * @return bool
     */
    abstract public function getSupportForUTCCasting(): bool;

    /**
    * Set UTC Datetime
    *
    * @param string $value
    * @return mixed
    */
    abstract public function setUTCDatetime(string $value): mixed;

    /**
    * Set support for attributes
    *
    * @param bool $support
    * @return bool
    */
    abstract public function setSupportForAttributes(bool $support): bool;

    /**
     * Does the adapter require booleans to be converted to integers (0/1)?
     *
     * @return bool
     */
    abstract public function getSupportForIntegerBooleans(): bool;

    /**
     * Does the adapter have support for ALTER TABLE locking modes?
     *
     * When enabled, adapters can specify lock behavior (e.g., LOCK=SHARED)
     * during ALTER TABLE operations to control concurrent access.
     *
     * @return bool
     */
    abstract public function getSupportForAlterLocks(): bool;

    /**
     * @param bool $enable
     *
     * @return $this
     */
    public function enableAlterLocks(bool $enable): self
    {
        $this->alterLocks = $enable;

        return $this;
    }

    /**
     * Handle non utf characters supported?
     *
     * @return bool
     */
    abstract public function getSupportNonUtfCharacters(): bool;

    /**
     * Does the adapter support trigram index?
     *
     * @return bool
     */
    abstract public function getSupportForTrigramIndex(): bool;

    /**
     * Is PCRE regex supported?
     * PCRE (Perl Compatible Regular Expressions) supports \b for word boundaries
     *
     * @return bool
     */
    abstract public function getSupportForPCRERegex(): bool;

    /**
     * Is POSIX regex supported?
     * POSIX regex uses \y for word boundaries instead of \b
     *
     * @return bool
     */
    abstract public function getSupportForPOSIXRegex(): bool;

    /**
     * Is regex supported at all?
     * Returns true if either PCRE or POSIX regex is supported
     *
     * @return bool
     */
    public function getSupportForRegex(): bool
    {
        return $this->getSupportForPCRERegex() || $this->getSupportForPOSIXRegex();
    }

    /**
     * Are ttl indexes supported?
     *
     * @return bool
     */
    public function getSupportForTTLIndexes(): bool
    {
        return false;
    }

    /**
     * Does the adapter support transaction retries?
     *
     * @return bool
     */
    abstract public function getSupportForTransactionRetries(): bool;

    /**
     * Does the adapter support nested transactions?
     *
     * @return bool
     */
    abstract public function getSupportForNestedTransactions(): bool;

    /**
     * @return mixed
     */
    abstract public function getDriver(): mixed;
}
