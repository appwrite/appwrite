<?php

namespace Utopia\Database\Adapter;

use Exception;
use MongoDB\BSON\Regex;
use MongoDB\BSON\UTCDateTime;
use stdClass;
use Utopia\Database\Adapter;
use Utopia\Database\Change;
use Utopia\Database\Database;
use Utopia\Database\DateTime;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Exception\Unique as UniqueException;
use Utopia\Database\Operator;
use Utopia\Database\Query;
use Utopia\Database\Validator\Authorization;
use Utopia\Mongo\Client;
use Utopia\Mongo\Exception as MongoException;

class Mongo extends Adapter
{
    /**
     * @var array<string>
     */
    private array $operators = [
        '$eq',
        '$ne',
        '$lt',
        '$lte',
        '$gt',
        '$gte',
        '$in',
        '$nin',
        '$text',
        '$search',
        '$or',
        '$and',
        '$match',
        '$regex',
        '$not',
        '$nor',
        '$exists',
        '$elemMatch',
        '$exists'
    ];

    protected Client $client;

    /**
     * Default batch size for cursor operations
     */
    private const DEFAULT_BATCH_SIZE = 1000;

    /**
     * Transaction/session state for MongoDB transactions
     * @var array<string, mixed>|null $session
     */
    private ?array $session = null; // Store session array from startSession
    protected int $inTransaction = 0;
    protected bool $supportForAttributes = true;

    /**
     * Constructor.
     *
     * Set connection and settings
     *
     * @param Client $client
     * @throws MongoException
     */
    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->client->connect();
    }

    public function getHostname(): string
    {
        return $this->client->getHost();
    }

    /**
     * Returns the current Mongo client
     * @return mixed
     */
    public function getDriver(): mixed
    {
        return $this->client;
    }

    public function setTimeout(int $milliseconds, string $event = Database::EVENT_ALL): void
    {
        if (!$this->getSupportForTimeouts()) {
            return;
        }

        $this->timeout = $milliseconds;
    }

    public function clearTimeout(string $event): void
    {
        parent::clearTimeout($event);

        $this->timeout = 0;
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \Throwable
     */
    public function withTransaction(callable $callback): mixed
    {
        // If the database is not a replica set, we can't use transactions
        if (!$this->client->isReplicaSet()) {
            return $callback();
        }

        // MongoDB doesn't support nested transactions/savepoints.
        // If already in a transaction, just run the callback directly.
        if ($this->inTransaction > 0) {
            return $callback();
        }

        // upsert + $setOnInsert hits WriteConflict (E112) under txn snapshot isolation.
        if ($this->skipDuplicates) {
            return $callback();
        }

        $sleep = 50_000; // 50 milliseconds
        $retries = 2;

        for ($attempts = 0; $attempts <= $retries; $attempts++) {
            try {
                $this->startTransaction();
                $result = $callback();
                $this->commitTransaction();
                return $result;
            } catch (\Throwable $action) {
                try {
                    $this->rollbackTransaction();
                } catch (\Throwable) {
                    // Throw the original exception, not the rollback one
                    // Since if it's a duplicate key error, the rollback will fail,
                    // and we want to throw the original exception.
                } finally {
                    // Ensure state is cleaned up even if rollback fails
                    if ($this->session) {
                        try {
                            $this->client->endSessions([$this->session]);
                        } catch (\Throwable $endSessionError) {
                            // Ignore errors when ending session during error cleanup
                        }
                    }
                    $this->inTransaction = 0;
                    $this->session = null;
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

                throw $action;
            }
        }

        throw new TransactionException('Failed to execute transaction');
    }

    public function startTransaction(): bool
    {
        // If the database is not a replica set, we can't use transactions
        if (!$this->client->isReplicaSet()) {
            return true;
        }

        try {
            if ($this->inTransaction === 0) {
                if (!$this->session) {
                    $this->session = $this->client->startSession(); // Get session array
                    $this->client->startTransaction($this->session); // Start the transaction
                }
            }
            $this->inTransaction++;
            return true;
        } catch (\Throwable $e) {
            $this->session = null;
            $this->inTransaction = 0;
            throw new DatabaseException('Failed to start transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function commitTransaction(): bool
    {
        // If the database is not a replica set, we can't use transactions
        if (!$this->client->isReplicaSet()) {
            return true;
        }

        try {
            if ($this->inTransaction === 0) {
                return false;
            }
            $this->inTransaction--;
            if ($this->inTransaction === 0) {
                if (!$this->session) {
                    return false;
                }
                try {
                    $result = $this->client->commitTransaction($this->session);
                } catch (MongoException $e) {
                    // If there's no active transaction, it may have been auto-aborted due to an error.
                    // This is not necessarily a failure, just return success since the transaction was already terminated.
                    $e = $this->processException($e);
                    if ($e instanceof TransactionException) {
                        $this->client->endSessions([$this->session]);
                        $this->session = null;
                        $this->inTransaction = 0;  // Reset counter when transaction is already terminated
                        return true;
                    }
                    throw $e;
                } catch (\Throwable $e) {
                    throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
                } finally {
                    if ($this->session) {
                        $this->client->endSessions([$this->session]);
                    }
                    $this->session = null;
                }

                return true;
            }
            return true;
        } catch (\Throwable $e) {
            // Ensure cleanup on any failure
            try {
                $this->client->endSessions([$this->session]);
            } catch (\Throwable $endSessionError) {
                // Ignore errors when ending session during error cleanup
            }
            $this->session = null;
            $this->inTransaction = 0;
            throw new DatabaseException('Failed to commit transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    public function rollbackTransaction(): bool
    {
        // If the database is not a replica set, we can't use transactions
        if (!$this->client->isReplicaSet()) {
            return true;
        }

        try {
            if ($this->inTransaction === 0) {
                return false;
            }
            $this->inTransaction--;
            if ($this->inTransaction === 0) {
                if (!$this->session) {
                    return false;
                }

                try {
                    $this->client->abortTransaction($this->session);
                } catch (\Throwable $e) {
                    $e = $this->processException($e);

                    if ($e instanceof TransactionException) {
                        // If there's no active transaction, it may have been auto-aborted due to an error.
                        // Just return success since the transaction was already terminated.
                        return true;
                    }

                    throw $e;
                } finally {
                    $this->client->endSessions([$this->session]);
                    $this->session = null;
                }

                return true;
            }
            return true;
        } catch (\Throwable $e) {
            try {
                $this->client->endSessions([$this->session]);
            } catch (\Throwable) {
                // Ignore errors when ending session during error cleanup
            }
            $this->session = null;
            $this->inTransaction = 0;

            throw new DatabaseException('Failed to rollback transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }
    }

    /**
     * Helper to add transaction/session context to command options if in transaction
     * Includes defensive check to ensure session is valid
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function getTransactionOptions(array $options = []): array
    {
        if ($this->inTransaction > 0 && $this->session !== null) {
            // Pass the session array directly - the client will handle the transaction state internally
            $options['session'] = $this->session;
        }
        return $options;
    }


    /**
     * Create a safe MongoDB regex pattern by escaping special characters
     *
     * @param string $value The user input to escape
     * @param string $pattern The pattern template (e.g., ".*%s.*" for contains)
     * @return Regex
     * @throws DatabaseException
     */
    private function createSafeRegex(string $value, string $pattern = '%s', string $flags = 'i'): Regex
    {
        $escaped = preg_quote($value, '/');

        // Validate that the pattern doesn't contain injection vectors
        if (preg_match('/\$[a-z]+/i', $escaped)) {
            throw new DatabaseException('Invalid regex pattern: potential injection detected');
        }

        $finalPattern = sprintf($pattern, $escaped);

        return new Regex($finalPattern, $flags);
    }

    /**
     * Ping Database
     *
     * @return bool
     * @throws Exception
     * @throws MongoException
     */
    public function ping(): bool
    {
        return $this->getClient()->query([
            'ping' => 1,
            'skipReadConcern' => true
        ])->ok ?? false;
    }

    public function reconnect(): void
    {
        $this->client->connect();
    }

    /**
     * Create Database
     *
     * @param string $name
     *
     * @return bool
     */
    public function create(string $name): bool
    {
        return true;
    }

    /**
     * Check if database exists
     * Optionally check if collection exists in database
     *
     * @param string $database database name
     * @param string|null $collection (optional) collection name
     *
     * @return bool
     * @throws Exception
     */
    public function exists(string $database, ?string $collection = null): bool
    {
        if (!\is_null($collection)) {
            $collection = $this->getNamespace() . "_" . $collection;
            try {
                // Use listCollections command with filter for O(1) lookup
                $result = $this->getClient()->query([
                    'listCollections' => 1,
                    'filter' => ['name' => $collection]
                ]);

                return !empty($result->cursor->firstBatch);
            } catch (\Exception $e) {
                return false;
            }
        }

        return $this->getClient()->selectDatabase() != null;
    }

    /**
     * List Databases
     *
     * @return array<Document>
     * @throws Exception
     */
    public function list(): array
    {
        $list = [];

        foreach ((array)$this->getClient()->listDatabaseNames() as $value) {
            $list[] = $value;
        }

        return $list;
    }

    /**
     * Delete Database
     *
     * @param string $name
     *
     * @return bool
     * @throws Exception
     */
    public function delete(string $name): bool
    {
        $this->getClient()->dropDatabase([], $name);

        return true;
    }

    /**
     * Create Collection
     *
     * @param string $name
     * @param array<Document> $attributes
     * @param array<Document> $indexes
     * @return bool
     * @throws Exception
     */
    public function createCollection(string $name, array $attributes = [], array $indexes = []): bool
    {
        $id = $this->getNamespace() . '_' . $this->filter($name);

        // In shared-tables mode or for metadata, the physical collection may
        // already exist for another tenant. Return early to avoid a
        // "Collection Exists" exception from the client.
        if (!$this->inTransaction && ($this->getSharedTables() || $name === Database::METADATA) && $this->exists($this->getNamespace(), $name)) {
            return true;
        }

        // Returns an array/object with the result document
        try {
            $options = $this->getTransactionOptions();
            $this->getClient()->createCollection($id, $options);
        } catch (MongoException $e) {
            $e = $this->processException($e);
            if ($e instanceof DuplicateException) {
                if ($this->getSharedTables() || $name === Database::METADATA) {
                    return true;
                }
                throw $e;
            }
            // Client throws code-0 "Collection Exists" when its pre-check
            // finds the collection. In shared-tables/metadata context this
            // is a no-op; otherwise re-throw as DuplicateException so
            // Database::createCollection() can run orphan reconciliation.
            if ($e->getCode() === 0 && stripos($e->getMessage(), 'Collection Exists') !== false) {
                if ($this->getSharedTables() || $name === Database::METADATA) {
                    return true;
                }
                throw new DuplicateException('Collection already exists', $e->getCode(), $e);
            }
            throw $e;
        }

        $internalIndex = [
            [
                'key' => ['_uid' => $this->getOrder(Database::ORDER_ASC)],
                'name' => '_uid',
                'unique' => true,
                'collation' => [
                    'locale' => 'en',
                    'strength' => 1,
                ],
            ],
            [
                'key' => ['_createdAt' => $this->getOrder(Database::ORDER_ASC)],
                'name' => '_createdAt',
            ],
            [
                'key' => ['_updatedAt' => $this->getOrder(Database::ORDER_ASC)],
                'name' => '_updatedAt',
            ],
            [
                'key' => ['_permissions' => $this->getOrder(Database::ORDER_ASC)],
                'name' => '_permissions',
            ]
        ];

        if ($this->sharedTables) {
            foreach ($internalIndex as &$index) {
                $index['key'] = array_merge(['_tenant' => $this->getOrder(Database::ORDER_ASC)], $index['key']);
            }
            unset($index);
        }

        try {
            $options = $this->getTransactionOptions();
            $indexesCreated = $this->client->createIndexes($id, $internalIndex, $options);
        } catch (\Exception $e) {
            throw $this->processException($e);
        }

        if (!$indexesCreated) {
            return false;
        }

        // Since attributes are not used by this adapter
        // Only act when $indexes is provided

        if (!empty($indexes)) {
            /**
             * Each new index has format ['key' => [$attribute => $order], 'name' => $name, 'unique' => $unique]
             */
            $newIndexes = [];

            $collectionAttributes = $attributes;

            // using $i and $j as counters to distinguish from $key
            foreach ($indexes as $i => $index) {

                $key = [];
                $unique = false;
                $attributes = $index->getAttribute('attributes');
                $orders = $index->getAttribute('orders');

                // If sharedTables, always add _tenant as the first key
                if ($this->shouldAddTenantToIndex($index)) {
                    $key['_tenant'] = $this->getOrder(Database::ORDER_ASC);
                }

                foreach ($attributes as $j => $attribute) {
                    $attribute = $this->filter($this->getInternalKeyForAttribute($attribute));

                    switch ($index->getAttribute('type')) {
                        case Database::INDEX_KEY:
                            $order = $this->getOrder($this->filter($orders[$j] ?? Database::ORDER_ASC));
                            break;
                        case Database::INDEX_FULLTEXT:
                            // MongoDB fulltext index is just 'text'
                            // Not using Database::INDEX_KEY for clarity
                            $order = 'text';
                            break;
                        case Database::INDEX_UNIQUE:
                            $order = $this->getOrder($this->filter($orders[$j] ?? Database::ORDER_ASC));
                            $unique = true;
                            break;
                        case Database::INDEX_TTL:
                            $order = $this->getOrder($this->filter($orders[$j] ?? Database::ORDER_ASC));
                            break;
                        default:
                            // index not supported
                            return false;
                    }

                    $key[$attribute] = $order;
                }

                $newIndexes[$i] = [
                    'key' => $key,
                    'name' => $this->filter($index->getId()),
                    'unique' => $unique
                ];

                if ($index->getAttribute('type') === Database::INDEX_FULLTEXT) {
                    $newIndexes[$i]['default_language'] = 'none';
                }

                // Handle TTL indexes
                if ($index->getAttribute('type') === Database::INDEX_TTL) {
                    $ttl = $index->getAttribute('ttl', 0);
                    if ($ttl > 0) {
                        $newIndexes[$i]['expireAfterSeconds'] = $ttl;
                    }
                }

                // Add partial filter for indexes to avoid indexing null values
                if (in_array($index->getAttribute('type'), [
                    Database::INDEX_UNIQUE,
                    Database::INDEX_KEY
                ])) {
                    $partialFilter = [];
                    foreach ($attributes as $attr) {
                        // Find the matching attribute in collectionAttributes to get its type
                        $attrType = $this->getMongoTypeCode(null);
                        foreach ($collectionAttributes as $collectionAttr) {
                            if ($collectionAttr->getId() === $attr) {
                                $attrType = $this->getMongoTypeCode($collectionAttr->getAttribute('type'));
                                break;
                            }
                        }

                        $attr = $this->filter($this->getInternalKeyForAttribute($attr));

                        // Use both $exists: true and $type to exclude nulls and ensure correct type
                        $partialFilter[$attr] = [
                            '$exists' => true,
                            '$type' => $attrType
                        ];
                    }
                    if (!empty($partialFilter)) {
                        $newIndexes[$i]['partialFilterExpression'] = $partialFilter;
                    }
                }
            }

            try {
                $options = $this->getTransactionOptions();
                $indexesCreated = $this->getClient()->createIndexes($id, $newIndexes, $options);
            } catch (\Exception $e) {
                throw $this->processException($e);
            }

            if (!$indexesCreated) {
                return false;
            }
        }

        return true;
    }

    /**
     * List Collections
     *
     * @return array<Document>
     * @throws Exception
     */
    public function listCollections(): array
    {
        $list = [];

        // Note: listCollections is a metadata operation that should not run in transactions
        // to avoid transaction conflicts and readConcern issues
        foreach ((array)$this->getClient()->listCollectionNames() as $value) {
            $list[] = $value;
        }

        return $list;
    }

    /**
     * Get Collection Size on disk
     * @param string $collection
     * @return int
     * @throws DatabaseException
     */
    public function getSizeOfCollectionOnDisk(string $collection): int
    {
        return $this->getSizeOfCollection($collection);
    }

    /**
     * Get Collection Size of raw data
     * @param string $collection
     * @return int
     * @throws DatabaseException
     */
    public function getSizeOfCollection(string $collection): int
    {
        $namespace = $this->getNamespace();
        $collection = $this->filter($collection);
        $collection = $namespace . '_' . $collection;

        $command = [
            'collStats' => $collection,
            'scale' => 1
        ];

        try {
            $result = $this->getClient()->query($command);
            if (is_object($result)) {
                return $result->totalSize;
            } else {
                throw new DatabaseException('No size found');
            }
        } catch (Exception $e) {
            throw new DatabaseException('Failed to get collection size: ' . $e->getMessage());
        }
    }

    /**
     * Delete Collection
     *
     * @param string $id
     * @return bool
     * @throws Exception
     */
    public function deleteCollection(string $id): bool
    {
        $id = $this->getNamespace() . '_' . $this->filter($id);
        return (!!$this->getClient()->dropCollection($id));
    }

    /**
     * Analyze a collection updating it's metadata on the database engine
     *
     * @param string $collection
     * @return bool
     */
    public function analyzeCollection(string $collection): bool
    {
        return false;
    }

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
     */
    public function createAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, bool $required = false): bool
    {
        return true;
    }

    /**
     * Create Attributes
     *
     * @param string $collection
     * @param array<array<string, mixed>> $attributes
     * @return bool
     * @throws DatabaseException
     */
    public function createAttributes(string $collection, array $attributes): bool
    {
        return true;
    }

    /**
     * Delete Attribute
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     * @throws DatabaseException
     * @throws MongoException
     */
    public function deleteAttribute(string $collection, string $id): bool
    {
        $collection = $this->getNamespace() . '_' . $this->filter($collection);

        $this->getClient()->update(
            $collection,
            [],
            ['$unset' => [$id => '']],
            multi: true
        );

        return true;
    }

    /**
     * Rename Attribute.
     *
     * @param string $collection
     * @param string $id
     * @param string $name
     * @return bool
     * @throws DatabaseException
     * @throws MongoException
     */
    public function renameAttribute(string $collection, string $id, string $name): bool
    {
        $collection = $this->getNamespace() . '_' . $this->filter($collection);

        $from    = $this->filter($this->getInternalKeyForAttribute($id));
        $to      = $this->filter($this->getInternalKeyForAttribute($name));
        $options = $this->getTransactionOptions();

        $this->getClient()->update(
            $collection,
            [],
            ['$rename' => [$from => $to]],
            multi: true,
            options: $options
        );

        return true;
    }

    /**
     * @param string $collection
     * @param string $relatedCollection
     * @param string $type
     * @param bool $twoWay
     * @param string $id
     * @param string $twoWayKey
     * @return bool
     */
    public function createRelationship(string $collection, string $relatedCollection, string $type, bool $twoWay = false, string $id = '', string $twoWayKey = ''): bool
    {
        return true;
    }

    /**
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
     * @throws DatabaseException
     * @throws MongoException
     */
    public function updateRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay,
        string $key,
        string $twoWayKey,
        string $side,
        ?string $newKey = null,
        ?string $newTwoWayKey = null
    ): bool {
        $collectionName = $this->getNamespace() . '_' . $this->filter($collection);
        $relatedCollectionName = $this->getNamespace() . '_' . $this->filter($relatedCollection);

        $escapedKey = $this->escapeMongoFieldName($key);
        $escapedNewKey = !\is_null($newKey) ? $this->escapeMongoFieldName($newKey) : null;
        $escapedTwoWayKey = $this->escapeMongoFieldName($twoWayKey);
        $escapedNewTwoWayKey = !\is_null($newTwoWayKey) ? $this->escapeMongoFieldName($newTwoWayKey) : null;

        $renameKey = [
            '$rename' => [
                $escapedKey => $escapedNewKey,
            ]
        ];

        $renameTwoWayKey = [
            '$rename' => [
                $escapedTwoWayKey => $escapedNewTwoWayKey,
            ]
        ];

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if (!\is_null($newKey) && $key !== $newKey) {
                    $this->getClient()->update($collectionName, updates: $renameKey, multi: true);
                }
                if ($twoWay && !\is_null($newTwoWayKey) && $twoWayKey !== $newTwoWayKey) {
                    $this->getClient()->update($relatedCollectionName, updates: $renameTwoWayKey, multi: true);
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($twoWay && !\is_null($newTwoWayKey) && $twoWayKey !== $newTwoWayKey) {
                    $this->getClient()->update($relatedCollectionName, updates: $renameTwoWayKey, multi: true);
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if (!\is_null($newKey) && $key !== $newKey) {
                    $this->getClient()->update($collectionName, updates: $renameKey, multi: true);
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $metadataCollection = new Document(['$id' => Database::METADATA]);
                $collectionDoc = $this->getDocument($metadataCollection, $collection);
                $relatedCollectionDoc = $this->getDocument($metadataCollection, $relatedCollection);

                if ($collectionDoc->isEmpty() || $relatedCollectionDoc->isEmpty()) {
                    throw new DatabaseException('Collection or related collection not found');
                }

                $junction = $side === Database::RELATION_SIDE_PARENT
                    ? $this->getNamespace() . '_' . $this->filter('_' . $collectionDoc->getSequence() . '_' . $relatedCollectionDoc->getSequence())
                    : $this->getNamespace() . '_' . $this->filter('_' . $relatedCollectionDoc->getSequence() . '_' . $collectionDoc->getSequence());

                if (!\is_null($newKey) && $key !== $newKey) {
                    $this->getClient()->update($junction, updates: $renameKey, multi: true);
                }
                if ($twoWay && !\is_null($newTwoWayKey) && $twoWayKey !== $newTwoWayKey) {
                    $this->getClient()->update($junction, updates: $renameTwoWayKey, multi: true);
                }
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        return true;
    }

    /**
     * @param string $collection
     * @param string $relatedCollection
     * @param string $type
     * @param bool $twoWay
     * @param string $key
     * @param string $twoWayKey
     * @param string $side
     * @return bool
     * @throws MongoException
     * @throws Exception
     */
    public function deleteRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay,
        string $key,
        string $twoWayKey,
        string $side
    ): bool {
        $collectionName = $this->getNamespace() . '_' . $this->filter($collection);
        $relatedCollectionName = $this->getNamespace() . '_' . $this->filter($relatedCollection);
        $escapedKey = $this->escapeMongoFieldName($key);
        $escapedTwoWayKey = $this->escapeMongoFieldName($twoWayKey);

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $this->getClient()->update($collectionName, [], ['$unset' => [$escapedKey => '']], multi: true);
                    if ($twoWay) {
                        $this->getClient()->update($relatedCollectionName, [], ['$unset' => [$escapedTwoWayKey => '']], multi: true);
                    }
                } elseif ($side === Database::RELATION_SIDE_CHILD) {
                    $this->getClient()->update($relatedCollectionName, [], ['$unset' => [$escapedTwoWayKey => '']], multi: true);
                    if ($twoWay) {
                        $this->getClient()->update($collectionName, [], ['$unset' => [$escapedKey => '']], multi: true);
                    }
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $this->getClient()->update($relatedCollectionName, [], ['$unset' => [$escapedTwoWayKey => '']], multi: true);
                } else {
                    $this->getClient()->update($collectionName, [], ['$unset' => [$escapedKey => '']], multi: true);
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $this->getClient()->update($collectionName, [], ['$unset' => [$escapedKey => '']], multi: true);
                } else {
                    $this->getClient()->update($relatedCollectionName, [], ['$unset' => [$escapedTwoWayKey => '']], multi: true);
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $metadataCollection = new Document(['$id' => Database::METADATA]);
                $collectionDoc = $this->getDocument($metadataCollection, $collection);
                $relatedCollectionDoc = $this->getDocument($metadataCollection, $relatedCollection);

                if ($collectionDoc->isEmpty() || $relatedCollectionDoc->isEmpty()) {
                    throw new DatabaseException('Collection or related collection not found');
                }

                $junction = $side === Database::RELATION_SIDE_PARENT
                    ? $this->getNamespace() . '_' . $this->filter('_' . $collectionDoc->getSequence() . '_' . $relatedCollectionDoc->getSequence())
                    : $this->getNamespace() . '_' . $this->filter('_' . $relatedCollectionDoc->getSequence() . '_' . $collectionDoc->getSequence());

                $this->getClient()->dropCollection($junction);
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        return true;
    }

    /**
     * Create Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array<string> $attributes
     * @param array<int> $lengths
     * @param array<string> $orders
     * @param array<string, string> $indexAttributeTypes
     * @param array<string, mixed> $collation
     * @param int $ttl
     * @return bool
     * @throws Exception
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths, array $orders, array $indexAttributeTypes = [], array $collation = [], int $ttl = 1): bool
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection);
        $id = $this->filter($id);
        $indexes = [];
        $options = [];
        $indexes['name'] = $id;

        // If sharedTables, always add _tenant as the first key
        if ($this->shouldAddTenantToIndex($type)) {
            $indexes['key']['_tenant'] = $this->getOrder(Database::ORDER_ASC);
        }

        // Types are keyed by attribute name, which the loop below replaces with the internal key
        $bsonTypes = \array_map(fn (string $attribute) => $this->getMongoTypeCode($indexAttributeTypes[$attribute] ?? null), $attributes);

        foreach ($attributes as $i => $attribute) {

            if (isset($indexAttributeTypes[$attribute]) && \str_contains($attribute, '.') && $indexAttributeTypes[$attribute] === Database::VAR_OBJECT) {
                $dottedAttributes = \explode('.', $attribute);
                $expandedAttributes = array_map(fn ($attr) => $this->filter($attr), $dottedAttributes);
                $attributes[$i] = implode('.', $expandedAttributes);
            } else {
                $attributes[$i] = $this->filter($this->getInternalKeyForAttribute($attribute));
            }

            $orderType = $this->getOrder($this->filter($orders[$i] ?? Database::ORDER_ASC));
            $indexes['key'][$attributes[$i]] = $orderType;

            switch ($type) {
                case Database::INDEX_KEY:
                    break;
                case Database::INDEX_FULLTEXT:
                    $indexes['key'][$attributes[$i]] = 'text';
                    break;
                case Database::INDEX_UNIQUE:
                    $indexes['unique'] = true;
                    break;
                case Database::INDEX_TTL:
                    break;
                default:
                    return false;
            }
        }

        /**
         * Collation
         *  1.  Moved under $indexes.
         *  2.  Updated format.
         *  3.  Avoid adding collation to fulltext index
         */
        if (!empty($collation) &&
            $type !== Database::INDEX_FULLTEXT) {
            $indexes['collation'] = [
                'locale' => 'en',
                'strength' => 1,
            ];
        }

        /**
         * Text index language configuration
         * Set to 'none' to disable stop words (words like 'other', 'the', 'a', etc.)
         * This ensures all words are indexed and searchable
         */
        if ($type === Database::INDEX_FULLTEXT) {
            $indexes['default_language'] = 'none';
        }

        // Handle TTL indexes
        if ($type === Database::INDEX_TTL && $ttl > 0) {
            $indexes['expireAfterSeconds'] = $ttl;
        }

        // Add partial filter for indexes to avoid indexing null values
        if (in_array($type, [Database::INDEX_UNIQUE, Database::INDEX_KEY])) {
            $partialFilter = [];
            foreach ($attributes as $i => $attr) {
                $partialFilter[$attr] = ['$exists' => true, '$type' => $bsonTypes[$i]];
            }
            if (!empty($partialFilter)) {
                $indexes['partialFilterExpression'] = $partialFilter;
            }
        }
        try {
            $result = $this->client->createIndexes($name, [$indexes], $options);

            // Wait for unique index to be fully built before returning
            // MongoDB builds indexes asynchronously, so we need to wait for completion
            // to ensure unique constraints are enforced immediately
            if ($type === Database::INDEX_UNIQUE) {
                $maxRetries = 10;
                $retryCount = 0;
                $baseDelay = 50000; // 50ms
                $maxDelay = 500000; // 500ms

                while ($retryCount < $maxRetries) {
                    try {
                        $indexList = $this->client->query([
                            'listIndexes' => $name
                        ]);

                        if (isset($indexList->cursor->firstBatch)) {
                            foreach ($indexList->cursor->firstBatch as $existingIndex) {
                                $indexArray = $this->client->toArray($existingIndex);

                                if (
                                    (isset($indexArray['name']) && $indexArray['name'] === $id) &&
                                    (!isset($indexArray['buildState']) || $indexArray['buildState'] === 'ready')
                                ) {
                                    return $result;
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        if ($retryCount >= $maxRetries - 1) {
                            throw new DatabaseException(
                                'Timeout waiting for index creation: ' . $e->getMessage(),
                                $e->getCode(),
                                $e
                            );
                        }
                    }

                    $delay = \min($baseDelay * (2 ** $retryCount), $maxDelay);
                    \usleep((int)$delay);
                    $retryCount++;
                }

                throw new DatabaseException("Index {$id} creation timed out after {$maxRetries} retries");
            }

            return $result;
        } catch (\Exception $e) {
            // Existing documents violate the new unique index, whatever index the message names
            if ($e->getCode() === 11000 || $e->getCode() === 11001) {
                throw new UniqueException('Unique index violation', $e->getCode(), $e);
            }

            throw $this->processException($e);
        }
    }

    /**
     * Rename Index.
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     *
     * @return bool
     * @throws Exception
     */
    public function renameIndex(string $collection, string $old, string $new): bool
    {
        $collection = $this->filter($collection);
        $metadataCollection = new Document(['$id' => Database::METADATA]);
        $collectionDocument = $this->getDocument($metadataCollection, $collection);
        $old = $this->filter($old);
        $new = $this->filter($new);
        $indexes = json_decode($collectionDocument['indexes'], true);
        $index = null;

        foreach ($indexes as $node) {
            if (($node['$id'] ?? $node['key'] ?? '') === $old) {
                $index = $node;
                break;
            }
        }

        // Extract attribute types from the collection document
        $indexAttributeTypes = [];
        if (isset($collectionDocument['attributes'])) {
            $attributes = json_decode($collectionDocument['attributes'], true);
            if ($attributes && $index) {
                // Map index attributes to their types
                foreach ($index['attributes'] as $attrName) {
                    foreach ($attributes as $attr) {
                        if ($attr['key'] === $attrName) {
                            $indexAttributeTypes[$attrName] = $attr['type'];
                            break;
                        }
                    }
                }
            }
        }

        try {
            if (!$index) {
                throw new DatabaseException('Index not found: ' . $old);
            }
            $deletedindex = $this->deleteIndex($collection, $old);
            $createdindex = $this->createIndex($collection, $new, $index['type'], $index['attributes'], $index['lengths'] ?? [], $index['orders'] ?? [], $indexAttributeTypes, [], $index['ttl'] ?? 0);
        } catch (\Exception $e) {
            throw $this->processException($e);
        }

        if ($deletedindex && $createdindex) {
            return true;
        }

        return false;
    }

    /**
     * Delete Index
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     * @throws Exception
     */
    public function deleteIndex(string $collection, string $id): bool
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection);
        $id = $this->filter($id);
        $this->getClient()->dropIndexes($name, [$id]);

        return true;
    }

    /**
     * Get Document
     *
     * @param Document $collection
     * @param string $id
     * @param Query[] $queries
     * @param bool $forUpdate
     * @return Document
     * @throws DatabaseException
     */
    public function getDocument(Document $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection->getId());

        $filters = ['_uid' => $id];

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection->getId());
        }


        $options = $this->getTransactionOptions();

        $selections = $this->getAttributeSelections($queries);
        $hasProjection = !empty($selections) && !\in_array('*', $selections);

        if ($hasProjection) {
            $options['projection'] = $this->getAttributeProjection($selections);
        }

        try {
            $result = $this->client->find($name, $filters, $options)->cursor->firstBatch;
        } catch (MongoException $e) {
            throw $this->processException($e);
        }

        if (empty($result)) {
            return new Document([]);
        }

        $resultArray = $this->client->toArray($result[0]);
        $result = $this->replaceChars('_', '$', $resultArray);
        $document = new Document($result);
        $document = $this->castingAfter($collection, $document);

        // Ensure missing relationship attributes are set to null (MongoDB doesn't store null fields)
        if (!$hasProjection) {
            $this->ensureRelationshipDefaults($collection, $document);
        }

        return $document;
    }

    /**
     * Create Document
     *
     * @param Document $collection
     * @param Document $document
     *
     * @return Document
     * @throws Exception
     */
    public function createDocument(Document $collection, Document $document): Document
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection->getId());

        $sequence = $document->getSequence();

        $document->removeAttribute('$sequence');

        if ($this->sharedTables) {
            $document->setAttribute('$tenant', $this->getTenant());
        }

        $record = $this->replaceChars('$', '_', (array)$document);

        // Insert manual id if set
        if (!empty($sequence)) {
            $record['_id'] = $sequence;
        }
        $options = $this->getTransactionOptions();
        $result = $this->insertDocument($name, $this->removeNullKeys($record), $options);
        $result = $this->replaceChars('_', '$', $result);
        // in order to keep the original object refrence.
        foreach ($result as $key => $value) {
            $document->setAttribute($key, $value);
        }

        return $document;
    }

    /**
     * Returns the document after casting from
     * @param Document $collection
     * @param Document $document
     * @return Document
     */
    public function castingAfter(Document $collection, Document $document): Document
    {
        if (!$this->getSupportForInternalCasting()) {
            return $document;
        }

        if ($document->isEmpty()) {
            return $document;
        }

        $attributes = $collection->getAttribute('attributes', []);

        $attributes = \array_merge($attributes, Database::INTERNAL_ATTRIBUTES);

        foreach ($attributes as $attribute) {
            $key = $attribute['$id'] ?? '';
            $type = $attribute['type'] ?? '';
            $array = $attribute['array'] ?? false;
            $value = $document->getAttribute($key);
            if (is_null($value)) {
                continue;
            }

            // Operators are resolved by the database (aggregation pipeline); skip casting
            if (Operator::isOperator($value)) {
                continue;
            }

            if ($array) {
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new DatabaseException('Failed to decode JSON for attribute ' . $key . ': ' . json_last_error_msg());
                    }
                    $value = $decoded;
                }
            } else {
                $value = [$value];
            }

            foreach ($value as $index => $node) {
                switch ($type) {
                    case Database::VAR_INTEGER:
                    case Database::VAR_BIGINT:
                        $node = (int)$node;
                        break;
                    case Database::VAR_DATETIME:
                        $node = $this->convertUTCDateToString($node);
                        break;
                    case Database::VAR_OBJECT:
                        // Convert stdClass objects to arrays for object attributes
                        if (is_object($node) && get_class($node) === stdClass::class) {
                            $node = $this->convertStdClassToArray($node);
                        }
                        break;
                    default:
                        break;
                }
                $value[$index] = $node;
            }
            $document->setAttribute($key, ($array) ? $value : $value[0]);
        }

        if (!$this->getSupportForAttributes()) {
            foreach ($document->getArrayCopy() as $key => $value) {
                // mongodb results out a stdclass for objects
                if (is_object($value) && get_class($value) === stdClass::class) {
                    $document->setAttribute($key, $this->convertStdClassToArray($value));
                } elseif ($value instanceof UTCDateTime) {
                    $document->setAttribute($key, $this->convertUTCDateToString($value));
                }
            }
        }
        return $document;
    }

    private function convertStdClassToArray(mixed $value): mixed
    {
        if (is_object($value) && get_class($value) === stdClass::class) {
            $properties = get_object_vars($value);

            return $properties === [] ? $value : array_map($this->convertStdClassToArray(...), $properties);
        }

        if (is_array($value)) {
            return array_map(
                fn ($v) => $this->convertStdClassToArray($v),
                $value
            );
        }

        return $value;
    }

    /**
     * Returns the document after casting to
     * @param Document $collection
     * @param Document $document
     * @return Document
     * @throws Exception
     */
    public function castingBefore(Document $collection, Document $document): Document
    {
        if (!$this->getSupportForInternalCasting()) {
            return $document;
        }

        if ($document->isEmpty()) {
            return $document;
        }

        $attributes = $collection->getAttribute('attributes', []);

        $attributes = \array_merge($attributes, Database::INTERNAL_ATTRIBUTES);

        foreach ($attributes as $attribute) {
            $key = $attribute['$id'] ?? '';
            $type = $attribute['type'] ?? '';
            $array = $attribute['array'] ?? false;

            $value = $document->getAttribute($key);
            if (is_null($value)) {
                continue;
            }

            // Operators are resolved by the database (aggregation pipeline); skip casting
            if (Operator::isOperator($value)) {
                continue;
            }

            if ($array) {
                if (is_string($value)) {
                    $decoded = json_decode($value, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new DatabaseException('Failed to decode JSON for attribute ' . $key . ': ' . json_last_error_msg());
                    }
                    $value = $decoded;
                }
            } else {
                $value = [$value];
            }

            foreach ($value as $index => $node) {
                switch ($type) {
                    case Database::VAR_DATETIME:
                        if (!($node instanceof UTCDateTime)) {
                            try {
                                $node = new UTCDateTime(new \DateTime($node));
                            } catch (\Throwable $e) {
                                throw new StructureException('Invalid datetime value for attribute "' . $key . '": ' . $e->getMessage());
                            }
                        }
                        break;
                    case Database::VAR_OBJECT:
                        $node = json_decode($node);
                        break;
                    default:
                        break;
                }
                $value[$index] = $node;
            }
            $document->setAttribute($key, ($array) ? $value : $value[0]);
        }
        $indexes = $collection->getAttribute('indexes');
        $ttlIndexes = array_filter($indexes, fn ($index) => $index->getAttribute('type') === Database::INDEX_TTL);

        if (!$this->getSupportForAttributes()) {
            foreach ($document->getArrayCopy() as $key => $value) {
                if (in_array($this->getInternalKeyForAttribute($key), Database::INTERNAL_ATTRIBUTE_KEYS)) {
                    continue;
                }
                if (is_string($value) && (in_array($key, $ttlIndexes) || $this->isExtendedISODatetime($value))) {
                    try {
                        $newValue = new UTCDateTime(new \DateTime($value));
                        $document->setAttribute($key, $newValue);
                    } catch (\Throwable $th) {
                        // skip -> a valid string
                    }
                }
            }
        }

        return $document;
    }

    /**
     * Create Documents in batches
     *
     * @param Document $collection
     * @param array<Document> $documents
     *
     * @return array<Document>
     *
     * @throws DuplicateException
     * @throws DatabaseException
     */
    public function createDocuments(Document $collection, array $documents): array
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection->getId());

        $options = $this->getTransactionOptions();
        $records = [];
        $hasSequence = null;
        $documents = \array_map(fn ($doc) => clone $doc, $documents);

        foreach ($documents as $document) {
            $sequence = $document->getSequence();

            if ($hasSequence === null) {
                $hasSequence = !empty($sequence);
            } elseif ($hasSequence == empty($sequence)) {
                throw new DatabaseException('All documents must have an sequence if one is set');
            }

            $record = $this->replaceChars('$', '_', (array)$document);

            if (!empty($sequence)) {
                $record['_id'] = $sequence;
            }

            $records[] = $record;
        }

        // insertMany aborts the txn on any duplicate; upsert + $setOnInsert no-ops instead.
        if ($this->skipDuplicates) {
            if (empty($records)) {
                return [];
            }

            $operations = [];
            foreach ($records as $record) {
                $filter = ['_uid' => $record['_uid'] ?? ''];
                if ($this->sharedTables) {
                    $filter['_tenant'] = $record['_tenant'] ?? $this->getTenant();
                }

                // Filter fields can't reappear in $setOnInsert (mongo path-conflict error).
                $setOnInsert = $record;
                unset($setOnInsert['_uid'], $setOnInsert['_tenant']);

                if (empty($setOnInsert)) {
                    continue;
                }

                $operations[] = [
                    'filter' => $filter,
                    'update' => ['$setOnInsert' => $setOnInsert],
                ];
            }

            try {
                $this->client->upsert($name, $operations, $options);
            } catch (MongoException $e) {
                throw $this->processException($e);
            }

            return $documents;
        }

        try {
            $documents = $this->client->insertMany($name, $records, $options);
        } catch (MongoException $e) {
            throw $this->processException($e);
        }

        foreach ($documents as $index => $document) {
            $documents[$index] = $this->replaceChars('_', '$', $this->client->toArray($document));
            $documents[$index] = new Document($documents[$index]);
        }

        return $documents;
    }

    /**
     *
     * @param string $name
     * @param array<string, mixed> $document
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     * @throws DuplicateException
     * @throws Exception
     */
    private function insertDocument(string $name, array $document, array $options = []): array
    {
        try {
            $result = $this->client->insert($name, $document, $options);
            $filters = [];
            $filters['_uid'] = $document['_uid'];

            if ($this->sharedTables) {
                $filters['_tenant'] = $this->getTenantFilters($name);
            }

            try {
                $result = $this->client->find(
                    $name,
                    $filters,
                    array_merge(['limit' => 1], $options)
                )->cursor->firstBatch[0];
            } catch (MongoException $e) {
                throw $this->processException($e);
            }

            return $this->client->toArray($result);
        } catch (MongoException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * Update Document
     *
     * @param Document $collection
     * @param string $id
     * @param Document $document
     * @param bool $skipPermissions
     * @return Document
     * @throws DuplicateException
     * @throws DatabaseException
     */
    public function updateDocument(Document $collection, string $id, Document $document, bool $skipPermissions): Document
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection->getId());

        $record = $document->getArrayCopy();
        $record = $this->replaceChars('$', '_', $record);

        $filters = [];
        $filters['_uid'] = $id;

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection->getId());
        }

        try {
            unset($record['_id']); // Don't update _id

            $options = $this->getTransactionOptions();

            $pipeline = $this->buildOperatorPipeline($record);
            if ($pipeline !== null) {
                $this->updateWithPipeline($name, $filters, $pipeline, $options);
            } else {
                $updateQuery = [
                    '$set' => $record,
                ];
                $this->client->update($name, $filters, $updateQuery, $options);
            }
        } catch (MongoException $e) {
            throw $this->processException($e);
        }

        return $document;
    }

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
    public function updateDocuments(Document $collection, Document $updates, array $documents): int
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection->getId());

        $options = $this->getTransactionOptions();
        $queries = [
            Query::equal('$sequence', \array_map(fn ($document) => $document->getSequence(), $documents))
        ];

        $filters = $this->buildFilters($queries);

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection->getId());
        }

        $record = $updates->getArrayCopy();
        $record = $this->replaceChars('$', '_', $record);

        try {
            $pipeline = $this->buildOperatorPipeline($record);
            if ($pipeline !== null) {
                return $this->updateWithPipeline($name, $filters, $pipeline, $options, multi: true);
            }

            $updateQuery = [
                '$set' => $record,
            ];

            return $this->client->update(
                $name,
                $filters,
                $updateQuery,
                $options,
                multi: true,
            );
        } catch (MongoException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * Build an aggregation pipeline update from a record that may contain Operator instances.
     *
     * Returns null when the record contains no operators, so the caller can fall back to a
     * plain `$set` update. When operators are present, every regular value is wrapped in
     * `$literal` (so it is never interpreted as an aggregation expression) and every operator
     * is translated into the equivalent aggregation expression, all merged into a single
     * `$set` stage.
     *
     * @param array<string, mixed> $record
     * @return array<int, array<string, mixed>>|null
     * @throws DatabaseException
     */
    private function buildOperatorPipeline(array $record): ?array
    {
        $hasOperators = false;
        foreach ($record as $value) {
            if (Operator::isOperator($value)) {
                $hasOperators = true;
                break;
            }
        }

        if (!$hasOperators) {
            return null;
        }

        $set = [];
        foreach ($record as $key => $value) {
            if (Operator::isOperator($value)) {
                $set[$key] = $this->getOperatorExpression($value, $key);
            } else {
                // Wrap literals so values are never parsed as aggregation expressions/field paths
                $set[$key] = ['$literal' => $value];
            }
        }

        return [['$set' => $set]];
    }

    /**
     * Execute an aggregation pipeline update.
     *
     * The Mongo client's update() helper wraps the update document in toObject(), which would
     * turn a pipeline (a list) into an object and break it. We therefore build the raw update
     * command and send it through query(), letting BSON encode the pipeline as an array.
     *
     * @param string $collection
     * @param array<string, mixed> $filters
     * @param array<int, array<string, mixed>> $pipeline
     * @param array<string, mixed> $options
     * @param bool $multi
     * @return int Number of matched documents
     * @throws MongoException
     */
    private function updateWithPipeline(string $collection, array $filters, array $pipeline, array $options = [], bool $multi = false): int
    {
        $command = [
            'update' => $collection,
            'updates' => [
                [
                    'q' => $this->client->toObject($filters),
                    'u' => $pipeline,
                    'multi' => $multi,
                    'upsert' => false,
                ],
            ],
        ];

        if (isset($options['session'])) {
            $command['session'] = $options['session'];
        }

        $result = $this->client->query($command);

        return \is_int($result) ? $result : 0;
    }

    /**
     * Execute a batch of upsert operations, supporting aggregation-pipeline updates.
     *
     * Mirrors the Mongo client's upsert() helper but does not wrap each update in toObject(),
     * so an update may be either a classic update document or an aggregation pipeline (list).
     *
     * @param string $collection
     * @param array<int, array{filter: array<string, mixed>, update: array<mixed>}> $operations
     * @param array<string, mixed> $options
     * @return int
     * @throws MongoException
     */
    private function executeUpsert(string $collection, array $operations, array $options = []): int
    {
        $updates = [];
        foreach ($operations as $op) {
            $updates[] = [
                'q' => $this->client->toObject($op['filter']),
                'u' => $op['update'],
                'upsert' => true,
                'multi' => false,
            ];
        }

        $command = \array_merge(
            [
                'update' => $collection,
                'updates' => $updates,
            ],
            $options
        );

        $result = $this->client->query($command);

        return \is_int($result) ? $result : 0;
    }

    /**
     * Translate an Operator into a MongoDB aggregation expression for use inside a `$set` stage.
     *
     * @param Operator $operator
     * @param string $field The (already escaped) field name the expression is assigned to
     * @return mixed
     * @throws DatabaseException
     */
    private function getOperatorExpression(Operator $operator, string $field): mixed
    {
        $ref = '$' . $field;
        $method = $operator->getMethod();
        $values = $operator->getValues();

        switch ($method) {
            // Numeric operators
            case Operator::TYPE_INCREMENT:
                $expr = ['$add' => [['$ifNull' => [$ref, 0]], $values[0] ?? 1]];
                if (isset($values[1])) {
                    $expr = ['$cond' => [['$lte' => [$expr, $values[1]]], $expr, ['$ifNull' => [$ref, 0]]]];
                }
                return $expr;

            case Operator::TYPE_DECREMENT:
                $expr = ['$subtract' => [['$ifNull' => [$ref, 0]], $values[0] ?? 1]];
                if (isset($values[1])) {
                    $expr = ['$cond' => [['$gte' => [$expr, $values[1]]], $expr, ['$ifNull' => [$ref, 0]]]];
                }
                return $expr;

            case Operator::TYPE_MULTIPLY:
                $expr = ['$multiply' => [['$ifNull' => [$ref, 0]], $values[0] ?? 1]];
                if (isset($values[1])) {
                    $expr = ['$cond' => [['$lte' => [$expr, $values[1]]], $expr, ['$ifNull' => [$ref, 0]]]];
                }
                return $expr;

            case Operator::TYPE_DIVIDE:
                $expr = ['$divide' => [['$ifNull' => [$ref, 0]], $values[0]]];
                if (isset($values[1])) {
                    $expr = ['$cond' => [['$gte' => [$expr, $values[1]]], $expr, ['$ifNull' => [$ref, 0]]]];
                }
                return $expr;

            case Operator::TYPE_MODULO:
                return ['$mod' => [['$ifNull' => [$ref, 0]], $values[0]]];

            case Operator::TYPE_POWER:
                $base = ['$ifNull' => [$ref, 0]];
                $exponent = $values[0];
                $expr = ['$pow' => [$base, $exponent]];
                if (isset($values[1])) {
                    // Apply the power only if the result stays within the max; otherwise leave the
                    // value unchanged. Overflow yields Infinity, which is greater than the max, so
                    // it correctly stays put.
                    $expr = ['$cond' => [['$lte' => [$expr, $values[1]]], $expr, $base]];

                    // Never compute $pow for an undefined input (0 to a negative power, or a
                    // negative base to a fractional exponent): it yields NaN, which Mongo orders
                    // below every number, so a plain `<= max` check would wrongly apply it. The
                    // exponent is constant, so only guard the base condition it can actually trigger.
                    $guards = [];
                    if ($exponent < 0) {
                        $guards[] = ['$eq' => [$base, 0]];
                    }
                    if (\floor($exponent) != $exponent) {
                        $guards[] = ['$lt' => [$base, 0]];
                    }
                    if (!empty($guards)) {
                        $undefined = \count($guards) === 1 ? $guards[0] : ['$or' => $guards];
                        $expr = ['$cond' => [$undefined, $base, $expr]];
                    }
                }
                return $expr;

                // String operators
            case Operator::TYPE_STRING_CONCAT:
                return ['$concat' => [['$ifNull' => [$ref, '']], ['$literal' => $values[0] ?? '']]];

            case Operator::TYPE_STRING_REPLACE:
                // An empty search is a no-op (matches SQL REPLACE semantics); MongoDB's
                // $replaceAll would otherwise insert the replacement between every character.
                if (($values[0] ?? '') === '') {
                    return ['$ifNull' => [$ref, '']];
                }
                return ['$replaceAll' => [
                    'input' => ['$ifNull' => [$ref, '']],
                    'find' => ['$literal' => $values[0]],
                    'replacement' => ['$literal' => $values[1] ?? ''],
                ]];

                // Boolean operators
            case Operator::TYPE_TOGGLE:
                return ['$not' => [['$ifNull' => [$ref, false]]]];

                // Array operators
            case Operator::TYPE_ARRAY_APPEND:
                return ['$concatArrays' => [['$ifNull' => [$ref, []]], ['$literal' => \array_values($values)]]];

            case Operator::TYPE_ARRAY_PREPEND:
                return ['$concatArrays' => [['$literal' => \array_values($values)], ['$ifNull' => [$ref, []]]]];

            case Operator::TYPE_ARRAY_INSERT:
                $index = (int)($values[0] ?? 0);
                $value = $values[1] ?? null;
                $size = ['$size' => '$$arr'];
                $before = ['$cond' => [['$lte' => [$index, 0]], [], ['$slice' => ['$$arr', $index]]]];
                $after = ['$cond' => [['$gte' => [$index, $size]], [], ['$slice' => ['$$arr', ['$subtract' => [$index, $size]]]]]];
                return ['$let' => [
                    'vars' => ['arr' => ['$ifNull' => [$ref, []]]],
                    'in' => ['$concatArrays' => [$before, ['$literal' => [$value]], $after]],
                ]];

            case Operator::TYPE_ARRAY_REMOVE:
                return ['$filter' => [
                    'input' => ['$ifNull' => [$ref, []]],
                    'cond' => ['$ne' => ['$$this', ['$literal' => $values[0] ?? null]]],
                ]];

            case Operator::TYPE_ARRAY_UNIQUE:
                // Preserve first-occurrence order while removing duplicates
                return ['$reduce' => [
                    'input' => ['$ifNull' => [$ref, []]],
                    'initialValue' => [],
                    'in' => ['$cond' => [
                        ['$in' => ['$$this', '$$value']],
                        '$$value',
                        ['$concatArrays' => ['$$value', ['$$this']]],
                    ]],
                ]];

            case Operator::TYPE_ARRAY_INTERSECT:
                // Keep elements present in the given set, preserving original order
                return ['$filter' => [
                    'input' => ['$ifNull' => [$ref, []]],
                    'cond' => ['$in' => ['$$this', ['$literal' => \array_values($values)]]],
                ]];

            case Operator::TYPE_ARRAY_DIFF:
                // Remove elements present in the given set, preserving original order
                return ['$filter' => [
                    'input' => ['$ifNull' => [$ref, []]],
                    'cond' => ['$not' => [['$in' => ['$$this', ['$literal' => \array_values($values)]]]]],
                ]];

            case Operator::TYPE_ARRAY_FILTER:
                return ['$filter' => [
                    'input' => ['$ifNull' => [$ref, []]],
                    'cond' => $this->getArrayFilterCondition((string)($values[0] ?? ''), $values[1] ?? null),
                ]];

                // Date operators
            case Operator::TYPE_DATE_ADD_DAYS:
                return ['$dateAdd' => [
                    'startDate' => ['$ifNull' => [$ref, '$$NOW']],
                    'unit' => 'day',
                    'amount' => (int)($values[0] ?? 0),
                ]];

            case Operator::TYPE_DATE_SUB_DAYS:
                return ['$dateSubtract' => [
                    'startDate' => ['$ifNull' => [$ref, '$$NOW']],
                    'unit' => 'day',
                    'amount' => (int)($values[0] ?? 0),
                ]];

            case Operator::TYPE_DATE_SET_NOW:
                return '$$NOW';

            default:
                throw new DatabaseException("Unsupported operator: {$method}");
        }
    }

    /**
     * Build the aggregation condition expression used by the arrayFilter operator.
     *
     * @param string $condition
     * @param mixed $compare
     * @return array<string, mixed>
     */
    private function getArrayFilterCondition(string $condition, mixed $compare): array
    {
        $value = ['$literal' => $compare];

        return match ($condition) {
            'equal' => ['$eq' => ['$$this', $value]],
            'notEqual' => ['$ne' => ['$$this', $value]],
            'greaterThan' => ['$gt' => ['$$this', $value]],
            'greaterThanEqual' => ['$gte' => ['$$this', $value]],
            'lessThan' => ['$lt' => ['$$this', $value]],
            'lessThanEqual' => ['$lte' => ['$$this', $value]],
            'isNull' => ['$eq' => ['$$this', null]],
            'isNotNull' => ['$ne' => ['$$this', null]],
            default => ['$literal' => true], // unknown condition keeps every element
        };
    }

    /**
     * @param Document $collection
     * @param string $attribute
     * @param array<Change> $changes
     * @return array<Document>
     * @throws DatabaseException
     */
    public function upsertDocuments(Document $collection, string $attribute, array $changes): array
    {
        if (empty($changes)) {
            return $changes;
        }

        try {
            $name = $this->getNamespace() . '_' . $this->filter($collection->getId());
            $attribute = $this->filter($attribute);

            $operations = [];
            $hasPipeline = false;
            foreach ($changes as $change) {
                $document = $change->getNew();
                $oldDocument = $change->getOld();
                $attributes = $document->getAttributes();
                $attributes['_uid'] = $document->getId();
                $attributes['_createdAt'] = $document['$createdAt'];
                $attributes['_updatedAt'] = $document['$updatedAt'];
                $attributes['_permissions'] = $document->getPermissions();

                if (!empty($document->getSequence())) {
                    $attributes['_id'] = $document->getSequence();
                }

                if ($this->sharedTables) {
                    $attributes['_tenant'] = $document->getTenant();
                }

                $record = $this->replaceChars('$', '_', $attributes);

                // Build filter for upsert
                $filters = ['_uid' => $document->getId()];

                if ($this->sharedTables) {
                    $filters['_tenant'] = $this->getTenantFilters($collection->getId());
                }

                unset($record['_id']); // Don't update _id

                // Get fields to unset for schemaless mode
                $unsetFields = $this->getUpsertAttributeRemovals($oldDocument, $document, $record);

                if (!empty($attribute)) {
                    // Get the attribute value before removing it from $set
                    $attributeValue = $record[$attribute] ?? 0;

                    // Remove the attribute from $set since we're incrementing it
                    // it is requierd to mimic the behaver of SQL on duplicate key update
                    unset($record[$attribute]);

                    // Also remove from unset if it was there
                    unset($unsetFields[$attribute]);

                    // Increment the specific attribute and update all other fields
                    $update = [
                        '$inc' => [$attribute => $attributeValue],
                        '$set' => $record
                    ];

                    if (!empty($unsetFields)) {
                        $update['$unset'] = $unsetFields;
                    }
                } else {
                    $pipeline = $this->buildOperatorPipeline($record);

                    if ($pipeline !== null) {
                        // Operator-based upsert: resolve operators via an aggregation pipeline
                        // so they apply atomically, with $ifNull defaults on insert.
                        $set = $pipeline[0]['$set'];

                        // Generate an _id only on insert; keep the existing one on update.
                        if (empty($document->getSequence())) {
                            $set['_id'] = ['$ifNull' => ['$_id', $this->client->createUuid()]];
                        }

                        $update = [['$set' => $set]];

                        if (!empty($unsetFields)) {
                            $update[] = ['$unset' => \array_keys($unsetFields)];
                        }

                        $hasPipeline = true;
                    } else {
                        // Update all fields
                        $update = [
                            '$set' => $record
                        ];

                        if (!empty($unsetFields)) {
                            $update['$unset'] = $unsetFields;
                        }

                        // Add UUID7 _id for new documents in upsert operations
                        if (empty($document->getSequence())) {
                            $update['$setOnInsert'] = [
                                '_id' => $this->client->createUuid()
                            ];
                        }
                    }
                }

                $operations[] = [
                    'filter' => $filters,
                    'update' => $update,
                ];
            }

            $options = $this->getTransactionOptions();

            if ($hasPipeline) {
                // The client's upsert() wraps each update in toObject(), which would corrupt a
                // pipeline (a list). Send the raw command so BSON encodes pipelines as arrays.
                $this->executeUpsert($name, $operations, $options);
            } else {
                $this->client->upsert(
                    $name,
                    $operations,
                    options: $options
                );
            }
        } catch (MongoException $e) {
            throw $this->processException($e);
        }

        return \array_map(fn ($change) => $change->getNew(), $changes);
    }

    /**
     * Get fields to unset for schemaless upsert operations
     *
     * @param Document $oldDocument
     * @param Document $newDocument
     * @param array<string, mixed> $record
     * @return array<string, string>
     */
    private function getUpsertAttributeRemovals(Document $oldDocument, Document $newDocument, array $record): array
    {
        $unsetFields = [];

        if ($this->getSupportForAttributes() || $oldDocument->isEmpty()) {
            return $unsetFields;
        }

        $oldUserAttributes = $oldDocument->getAttributes();
        $newUserAttributes = $newDocument->getAttributes();

        $protectedFields = ['_uid', '_id', '_createdAt', '_updatedAt', '_permissions', '_tenant'];

        foreach ($oldUserAttributes as $originalKey => $originalValue) {
            if (in_array($originalKey, $protectedFields) || array_key_exists($originalKey, $newUserAttributes)) {
                continue;
            }

            $transformed = $this->replaceChars('$', '_', [$originalKey => $originalValue]);
            $dbKey = array_key_first($transformed);

            if ($dbKey && !array_key_exists($dbKey, $record) && !in_array($dbKey, $protectedFields)) {
                $unsetFields[$dbKey] = '';
            }
        }

        return $unsetFields;
    }

    /**
     * Get sequences for documents that were created
     *
     * @param string $collection
     * @param array<Document> $documents
     * @return array<Document>
     * @throws DatabaseException
     * @throws MongoException
     */
    public function getSequences(string $collection, array $documents): array
    {
        $documentIds = [];
        $documentTenants = [];
        foreach ($documents as $document) {
            if (empty($document->getSequence())) {
                $documentIds[] = $document->getId();

                if ($this->sharedTables) {
                    $documentTenants[] = $document->getTenant();
                }
            }
        }

        if (empty($documentIds)) {
            return $documents;
        }

        $sequences = [];
        $name = $this->getNamespace() . '_' . $this->filter($collection);

        $filters = ['_uid' => ['$in' => $documentIds]];

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection, $documentTenants);
        }
        try {
            // Use cursor paging for large result sets
            $options = [
                'projection' => ['_uid' => 1, '_id' => 1],
                'batchSize' => self::DEFAULT_BATCH_SIZE
            ];

            $options = $this->getTransactionOptions($options);
            $response = $this->client->find($name, $filters, $options);
            $results = $response->cursor->firstBatch ?? [];

            // Process first batch
            foreach ($results as $result) {
                $sequences[$result->_uid] = (string)$result->_id;
            }

            // Get cursor ID for subsequent batches
            $cursorId = $response->cursor->id ?? null;

            // Continue fetching with getMore
            while ($cursorId && $cursorId !== 0) {
                $moreResponse = $this->client->getMore((int)$cursorId, $name, self::DEFAULT_BATCH_SIZE);
                $moreResults = $moreResponse->cursor->nextBatch ?? [];

                if (empty($moreResults)) {
                    break;
                }

                foreach ($moreResults as $result) {
                    $sequences[$result->_uid] = (string)$result->_id;
                }

                // Update cursor ID for next iteration
                $cursorId = (int)($moreResponse->cursor->id ?? 0);
            }
        } catch (MongoException $e) {
            throw $this->processException($e);
        }

        foreach ($documents as $document) {
            if (isset($sequences[$document->getId()])) {
                $document['$sequence'] = $sequences[$document->getId()];
            }
        }

        return $documents;
    }

    /**
     * Increase or decrease an attribute value
     *
     * @param string $collection
     * @param string $id
     * @param string $attribute
     * @param int|float $value
     * @param string $updatedAt
     * @param int|float|null $min
     * @param int|float|null $max
     * @return bool
     * @throws DatabaseException
     * @throws MongoException
     * @throws Exception
     */
    public function increaseDocumentAttribute(string $collection, string $id, string $attribute, int|float $value, string $updatedAt, int|float|null $min = null, int|float|null $max = null): bool
    {
        $attribute = $this->filter($attribute);
        $filters = ['_uid' => $id];

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection);
        }

        if ($max !== null || $min !== null) {
            $filters[$attribute] = [];
            if ($max !== null) {
                $filters[$attribute]['$lte'] = $max;
            }
            if ($min !== null) {
                $filters[$attribute]['$gte'] = $min;
            }
        }

        $options = $this->getTransactionOptions();
        try {
            $this->client->update(
                $this->getNamespace() . '_' . $this->filter($collection),
                $filters,
                [
                    '$inc' => [$attribute => $value],
                    '$set' => ['_updatedAt' => $this->toMongoDatetime($updatedAt)],
                ],
                options: $options
            );
        } catch (MongoException $e) {
            throw $this->processException($e);
        }

        return true;
    }

    /**
     * Delete Document
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     * @throws Exception
     */
    public function deleteDocument(string $collection, string $id): bool
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection);

        $filters = [];
        $filters['_uid'] = $id;

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection);
        }

        $options = $this->getTransactionOptions();
        $result = $this->client->delete($name, $filters, 1, [], $options);

        return (!!$result);
    }

    /**
     * Delete Documents
     *
     * @param string $collection
     * @param array<string> $sequences
     * @param array<string> $permissionIds
     * @return int
     * @throws DatabaseException
     */
    public function deleteDocuments(string $collection, array $sequences, array $permissionIds): int
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection);

        foreach ($sequences as $index => $sequence) {
            $sequences[$index] = $sequence;
        }

        $filters = $this->buildFilters([new Query(Query::TYPE_EQUAL, '_id', $sequences)]);

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection);
        }

        $filters = $this->replaceInternalIdsKeys($filters, '$', '_', $this->operators);

        $options = $this->getTransactionOptions();

        try {
            return $this->client->delete(
                collection: $name,
                filters: $filters,
                limit: 0,
                options: $options
            );
        } catch (MongoException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * Update Attribute.
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size
     * @param bool $signed
     * @param bool $array
     * @param string $newKey
     *
     * @return bool
     */
    public function updateAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, ?string $newKey = null, bool $required = false): bool
    {
        if (!empty($newKey) && $newKey !== $id) {
            return $this->renameAttribute($collection, $id, $newKey);
        }
        return true;
    }

    /**
     * TODO Consider moving this to adapter.php
     * @param string $attribute
     * @return string
     */
    protected function getInternalKeyForAttribute(string $attribute): string
    {
        return match ($attribute) {
            '$id' => '_uid',
            '$sequence' => '_id',
            '$collection' => '_collection',
            '$tenant' => '_tenant',
            '$createdAt' => '_createdAt',
            '$updatedAt' => '_updatedAt',
            '$deletedAt' => '_deletedAt',
            '$permissions' => '_permissions',
            default => $attribute
        };
    }

    /**
     * @return list<string>
     */
    private function permissionStrings(string $type): array
    {
        $permissions = [];
        foreach ($this->authorization->getRoles() as $role) {
            $permissions[] = $type . '("' . $role . '")';
        }

        return $permissions;
    }

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
     *
     * @return array<Document>
     * @throws Exception
     * @throws TimeoutException
     */
    public function find(Document $collection, array $queries = [], ?int $limit = 25, ?int $offset = null, array $orderAttributes = [], array $orderTypes = [], array $cursor = [], string $cursorDirection = Database::CURSOR_AFTER, string $forPermission = Database::PERMISSION_READ): array
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection->getId());
        $queries = array_map(fn ($query) => clone $query, $queries);

        // Escape query attribute names that contain dots and match collection attributes
        // (to distinguish from nested object paths like profile.level1.value)
        $this->escapeQueryAttributes($collection, $queries);

        $filters = $this->buildFilters($queries);

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection->getId());
        }

        // permissions
        if ($this->authorization->getStatus()) {
            $filters['_permissions']['$in'] = $this->permissionStrings($forPermission);
        }

        $options = [];

        if (!\is_null($limit)) {
            $options['limit'] = $limit;
        }
        if (!\is_null($offset)) {
            $options['skip'] = $offset;
        }

        if ($this->timeout) {
            $options['maxTimeMS'] = $this->timeout;
        }

        $selections = $this->getAttributeSelections($queries);
        $hasProjection = !empty($selections) && !\in_array('*', $selections);
        if ($hasProjection) {
            $options['projection'] = $this->getAttributeProjection($selections);
        }

        // Add transaction context to options
        $options = $this->getTransactionOptions($options);

        $orFilters = [];

        foreach ($orderAttributes as $i => $originalAttribute) {
            $attribute = $this->getInternalKeyForAttribute($originalAttribute);
            $attribute = $this->filter($attribute);

            $orderType = $this->filter($orderTypes[$i] ?? Database::ORDER_ASC);
            $direction = $orderType;

            /** Get sort direction  ASC || DESC **/
            if ($cursorDirection === Database::CURSOR_BEFORE) {
                $direction = ($direction === Database::ORDER_ASC)
                    ? Database::ORDER_DESC
                    : Database::ORDER_ASC;
            }

            $options['sort'][$attribute] = $this->getOrder($direction);

            /** Get operator sign  '$lt' ? '$gt' **/
            $operator = $cursorDirection === Database::CURSOR_AFTER
                ? ($orderType === Database::ORDER_DESC ? Query::TYPE_LESSER : Query::TYPE_GREATER)
                : ($orderType === Database::ORDER_DESC ? Query::TYPE_GREATER : Query::TYPE_LESSER);

            $operator = $this->getQueryOperator($operator);

            if (!empty($cursor)) {

                $andConditions = [];
                for ($j = 0; $j < $i; $j++) {
                    $originalPrev = $orderAttributes[$j];
                    $prevAttr = $this->filter($this->getInternalKeyForAttribute($originalPrev));
                    $tmp = $cursor[$originalPrev];
                    $andConditions[] = [
                        $prevAttr => $tmp
                    ];
                }

                $tmp = $cursor[$originalAttribute];

                if ($originalAttribute === '$sequence') {
                    /** If there is only $sequence attribute in $orderAttributes skip Or And  operators **/
                    if (count($orderAttributes) === 1) {
                        $filters[$attribute] = [
                            $operator => $tmp
                        ];
                        break;
                    }
                }

                $andConditions[] = [
                    $attribute => [
                        $operator => $tmp
                    ]
                ];

                $orFilters[] = [
                    '$and' => $andConditions
                ];
            }
        }

        if (!empty($orFilters)) {
            $filters['$or'] = $orFilters;
        }

        // Translate operators and handle time filters
        $filters = $this->replaceInternalIdsKeys($filters, '$', '_', $this->operators);

        $found = [];
        $cursorId = null;

        try {
            // Use proper cursor iteration with reasonable batch size
            $options['batchSize'] = self::DEFAULT_BATCH_SIZE;

            $response = $this->client->find($name, $filters, $options);
            $results = $response->cursor->firstBatch ?? [];
            // Process first batch
            foreach ($results as $result) {
                $record = $this->replaceChars('_', '$', (array)$result);
                $found[] = new Document($this->convertStdClassToArray($record));
            }

            // Get cursor ID for subsequent batches
            $cursorId = $response->cursor->id ?? null;

            // Continue fetching with getMore
            while ($cursorId && $cursorId !== 0) {
                $moreResponse = $this->client->getMore((int)$cursorId, $name, self::DEFAULT_BATCH_SIZE);
                $moreResults = $moreResponse->cursor->nextBatch ?? [];

                if (empty($moreResults)) {
                    break;
                }

                foreach ($moreResults as $result) {
                    $record = $this->replaceChars('_', '$', (array)$result);
                    $found[] = new Document($this->convertStdClassToArray($record));
                }

                $cursorId = (int)($moreResponse->cursor->id ?? 0);
            }
        } catch (MongoException $e) {
            throw $this->processException($e);
        } finally {
            // Ensure cursor is killed if still active to prevent resource leak
            if (isset($cursorId) && $cursorId !== 0) {
                try {
                    $this->client->query([
                        'killCursors' => $name,
                        'cursors' => [(int)$cursorId]
                    ]);
                } catch (\Exception $e) {
                    // Ignore errors during cursor cleanup
                }
            }
        }

        if ($cursorDirection === Database::CURSOR_BEFORE) {
            $found = array_reverse($found);
        }

        // Ensure missing relationship attributes are set to null (MongoDB doesn't store null fields)
        if (!$hasProjection) {
            foreach ($found as $document) {
                $this->ensureRelationshipDefaults($collection, $document);
            }
        }

        return $found;
    }


    /**
     * Converts Appwrite database type to MongoDB BSON type code.
     *
     * Numbers use the 'number' alias: an integer is stored as int32 or int64
     * depending on its value, and a float attribute can hold an integer.
     * An unknown type (schemaless, internal attributes) matches every stored
     * value type except null.
     *
     * @param string|null $appwriteType
     * @return string|array<string>
     */
    private function getMongoTypeCode(?string $appwriteType): string|array
    {
        return match ($appwriteType) {
            Database::VAR_STRING => 'string',
            Database::VAR_VARCHAR => 'string',
            Database::VAR_TEXT => 'string',
            Database::VAR_MEDIUMTEXT => 'string',
            Database::VAR_LONGTEXT => 'string',
            Database::VAR_INTEGER => 'number',
            Database::VAR_BIGINT => 'number',
            Database::VAR_FLOAT => 'number',
            Database::VAR_BOOLEAN => 'bool',
            Database::VAR_DATETIME => 'date',
            Database::VAR_ID => 'string',
            Database::VAR_UUID7 => 'string',
            null => ['string', 'number', 'bool', 'date', 'object'],
            default => 'string'
        };
    }

    /**
     * Converts timestamp to Mongo\BSON datetime format.
     *
     * @param string $dt
     * @return UTCDateTime
     * @throws Exception
     */
    private function toMongoDatetime(string $dt): UTCDateTime
    {
        return new UTCDateTime(new \DateTime($dt));
    }

    /**
     * Recursive function to replace chars in array keys, while
     * skipping any that are explicitly excluded.
     *
     * @param array<string, mixed> $array
     * @param string $from
     * @param string $to
     * @param array<string> $exclude
     * @return array<string, mixed>
     */
    private function replaceInternalIdsKeys(array $array, string $from, string $to, array $exclude = []): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            if (!in_array($key, $exclude)) {
                $key = str_replace($from, $to, $key);
            }

            $result[$key] = is_array($value)
                ? $this->replaceInternalIdsKeys($value, $from, $to, $exclude)
                : $value;
        }

        return $result;
    }


    /**
     * Count Documents
     *
     * @param Document $collection
     * @param array<Query> $queries
     * @param int|null $max
     * @return int
     * @throws Exception
     */
    public function count(Document $collection, array $queries = [], ?int $max = null): int
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection->getId());

        $queries = array_map(fn ($query) => clone $query, $queries);

        // Escape query attribute names that contain dots and match collection attributes
        $this->escapeQueryAttributes($collection, $queries);

        $filters = [];

        // Build filters from queries
        $filters = $this->buildFilters($queries);

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection->getId());
        }

        // Add permissions filter if authorization is enabled
        if ($this->authorization->getStatus()) {
            $filters['_permissions']['$in'] = $this->permissionStrings(Database::PERMISSION_READ);
        }

        /**
         * Use MongoDB aggregation pipeline for accurate counting
         * Accuracy and Sharded Clusters
         * "On a sharded cluster, the count command when run without a query predicate can result in an inaccurate
         * count if orphaned documents exist or if a chunk migration is in progress.
         * To avoid these situations, on a sharded cluster, use the db.collection.aggregate() method"
         * https://www.mongodb.com/docs/manual/reference/command/count/#response
         **/

        $options = $this->getTransactionOptions();

        if ($this->timeout) {
            $options['maxTimeMS'] = $this->timeout;
        }

        $pipeline = [];

        // Add match stage if filters are provided
        if (!empty($filters)) {
            $pipeline[] = ['$match' => $this->client->toObject($filters)];
        }

        // Add limit stage if specified
        if (!\is_null($max) && $max > 0) {
            $pipeline[] = ['$limit' => $max];
        }

        // Use $group and $sum when limit is specified, $count when no limit
        // Note: $count stage doesn't works well with $limit in the same pipeline
        // When limit is specified, we need to use $group + $sum to count the limited documents
        if (!\is_null($max) && $max > 0) {
            // When limit is specified, use $group and $sum to count limited documents
            $pipeline[] = [
                '$group' => [
                    '_id' => null,
                    'total' => ['$sum' => 1]]
            ];
        } else {
            // When no limit is passed, use $count for better performance
            $pipeline[] = [
                '$count' => 'total'
            ];
        }

        try {

            $result = $this->client->aggregate($name, $pipeline, $options);

            // Aggregation returns stdClass with cursor property containing firstBatch
            if (isset($result->cursor) && !empty($result->cursor->firstBatch)) {
                $firstResult = $result->cursor->firstBatch[0];

                // Handle both $count and $group response formats
                if (isset($firstResult->total)) {
                    return (int)$firstResult->total;
                }
            }

            return 0;
        } catch (MongoException $e) {
            $processed = $this->processException($e);
            if ($processed instanceof TimeoutException) {
                throw $processed;
            }

            return 0;
        }
    }


    /**
     * Sum an attribute
     *
     * @param Document $collection
     * @param string $attribute
     * @param array<Query> $queries
     * @param int|null $max
     *
     * @return int|float
     * @throws Exception
     */

    public function sum(Document $collection, string $attribute, array $queries = [], ?int $max = null): float|int
    {
        $name = $this->getNamespace() . '_' . $this->filter($collection->getId());

        // queries
        $queries = array_map(fn ($query) => clone $query, $queries);
        $filters = $this->buildFilters($queries);

        if ($this->sharedTables) {
            $filters['_tenant'] = $this->getTenantFilters($collection->getId());
        }

        // permissions
        if ($this->authorization->getStatus()) { // skip if authorization is disabled
            $filters['_permissions']['$in'] = $this->permissionStrings(Database::PERMISSION_READ);
        }

        // using aggregation to get sum an attribute as described in
        // https://docs.mongodb.com/manual/reference/method/db.collection.aggregate/
        // Pipeline consists of stages to aggregation, so first we set $match
        // that will load only documents that matches the filters provided and passes to the next stage
        // then we set $limit (if $max is provided) so that only $max documents will be passed to the next stage
        // finally we use $group stage to sum the provided attribute that matches the given filters and max
        // We pass the $pipeline to the aggregate method, which returns a cursor, then we get
        // the array of results from the cursor, and we return the total sum of the attribute
        $pipeline = [];
        if (!empty($filters)) {
            $pipeline[] = ['$match' => $filters];
        }
        if (!empty($max)) {
            $pipeline[] = ['$limit' => $max];
        }
        $pipeline[] = [
            '$group' => [
                '_id' => null,
                'total' => ['$sum' => '$' . $attribute],
            ],
        ];

        $options = $this->getTransactionOptions();

        if ($this->timeout) {
            $options['maxTimeMS'] = $this->timeout;
        }

        try {
            return $this->client->aggregate($name, $pipeline, $options)->cursor->firstBatch[0]->total ?? 0;
        } catch (MongoException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * @return Client
     *
     * @throws Exception
     */
    protected function getClient(): Client
    {
        return $this->client;
    }

    /**
     * Escape a field name for MongoDB storage.
     * MongoDB field names cannot start with $ or contain dots.
     *
     * @param string $name
     * @return string
     */
    protected function escapeMongoFieldName(string $name): string
    {
        if (\str_starts_with($name, '$')) {
            $name = '_' . \substr($name, 1);
        }
        if (\str_contains($name, '.')) {
            $name = \str_replace('.', '__dot__', $name);
        }
        return $name;
    }

    /**
     * Escape query attribute names that contain dots and match known collection attributes.
     * This distinguishes field names with dots (like 'collectionSecurity.Parent') from
     * nested object paths (like 'profile.level1.value').
     *
     * @param Document $collection
     * @param array<Query> $queries
     */
    protected function escapeQueryAttributes(Document $collection, array $queries): void
    {
        $attributes = $collection->getAttribute('attributes', []);
        $dotAttributes = [];
        foreach ($attributes as $attribute) {
            $key = $attribute['$id'] ?? '';
            if (\str_contains($key, '.') || \str_starts_with($key, '$')) {
                $dotAttributes[$key] = $this->escapeMongoFieldName($key);
            }
        }

        if (empty($dotAttributes)) {
            return;
        }

        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if (isset($dotAttributes[$attr])) {
                $query->setAttribute($dotAttributes[$attr]);
            }
        }
    }

    /**
     * Ensure relationship attributes have default null values in MongoDB documents.
     * MongoDB doesn't store null fields, so we need to add them for schema compatibility.
     *
     * @param Document $collection
     * @param Document $document
     */
    protected function ensureRelationshipDefaults(Document $collection, Document $document): void
    {
        $attributes = $collection->getAttribute('attributes', []);
        foreach ($attributes as $attribute) {
            $key = $attribute['$id'] ?? '';
            $type = $attribute['type'] ?? '';
            if ($type === Database::VAR_RELATIONSHIP && !$document->offsetExists($key)) {
                $options = $attribute['options'] ?? [];
                $twoWay = $options['twoWay'] ?? false;
                $side = $options['side'] ?? '';
                $relationType = $options['relationType'] ?? '';

                // Determine if this relationship stores data on this collection's documents
                // Only set null defaults for relationships that would have a column in SQL
                $storesData = match ($relationType) {
                    Database::RELATION_ONE_TO_ONE => $side === Database::RELATION_SIDE_PARENT || $twoWay,
                    Database::RELATION_ONE_TO_MANY => $side === Database::RELATION_SIDE_CHILD,
                    Database::RELATION_MANY_TO_ONE => $side === Database::RELATION_SIDE_PARENT,
                    Database::RELATION_MANY_TO_MANY => false,
                    default => false,
                };

                if ($storesData) {
                    $document->setAttribute($key, null);
                }
            }
        }
    }

    /**
     * Keys cannot begin with $ in MongoDB
     * Convert $ prefix to _ on $id, $permissions, and $collection
     *
     * @param string $from
     * @param string $to
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    protected function replaceChars(string $from, string $to, array $array): array
    {
        $filter = [
            'permissions',
            'createdAt',
            'updatedAt',
            'collection'
        ];

        // First pass: recursively process array values and collect keys to rename
        $keysToRename = [];
        foreach ($array as $k => $v) {
            if (is_array($v)) {
                $array[$k] = $this->replaceChars($from, $to, $v);
            }

            $newKey = $k;

            // Handle key replacement for filtered attributes
            $clean_key = str_replace($from, "", $k);
            if (in_array($clean_key, $filter)) {
                $newKey = str_replace($from, $to, $k);
            } elseif (\is_string($k) && \str_starts_with($k, $from) && !in_array($k, ['$id', '$sequence', '$tenant', '_uid', '_id', '_tenant'])) {
                // Handle any other key starting with the 'from' char (e.g. user-defined $-prefixed keys)
                $newKey = $to . \substr($k, \strlen($from));
            }

            // Handle dot escaping in MongoDB field names
            if ($from === '$' && \is_string($k) && \str_contains($newKey, '.')) {
                $newKey = \str_replace('.', '__dot__', $newKey);
            } elseif ($from === '_' && \is_string($k) && \str_contains($k, '__dot__')) {
                $newKey = \str_replace('__dot__', '.', $newKey);
            }

            if ($newKey !== $k) {
                $keysToRename[$k] = $newKey;
            }
        }

        foreach ($keysToRename as $oldKey => $newKey) {
            $array[$newKey] = $array[$oldKey];
            unset($array[$oldKey]);
        }

        // Handle special attribute mappings
        if ($from === '_') {
            if (isset($array['_id'])) {
                $array['$sequence'] = (string)$array['_id'];
                unset($array['_id']);
            }
            if (isset($array['_uid'])) {
                $array['$id'] = $array['_uid'];
                unset($array['_uid']);
            }
            if (isset($array['_tenant'])) {
                $array['$tenant'] = $array['_tenant'];
                unset($array['_tenant']);
            }
        } elseif ($from === '$') {
            if (isset($array['$id'])) {
                $array['_uid'] = $array['$id'];
                unset($array['$id']);
            }
            if (isset($array['$sequence'])) {
                $array['_id'] = $array['$sequence'];
                unset($array['$sequence']);
            }
            if (isset($array['$tenant'])) {
                $array['_tenant'] = $array['$tenant'];
                unset($array['$tenant']);
            }
        }

        return $array;
    }

    /**
     * @param array<Query> $queries
     * @param string $separator
     * @return array<mixed>
     * @throws Exception
     */
    protected function buildFilters(array $queries, string $separator = '$and'): array
    {
        $filters = [];
        $queries = Query::groupByType($queries)['filters'];

        foreach ($queries as $query) {
            /* @var $query Query */
            if ($query->isNested()) {
                if ($query->getMethod() === Query::TYPE_ELEM_MATCH) {
                    $filters[$separator][] = [
                        $query->getAttribute() => [
                            '$elemMatch' => $this->buildFilters($query->getValues(), $separator)
                        ]
                    ];
                    continue;
                }

                $operator = $this->getQueryOperator($query->getMethod());

                $filters[$separator][] = $this->buildFilters($query->getValues(), $operator);
            } else {
                $filters[$separator][] = $this->buildFilter($query);
            }
        }

        return $filters;
    }

    /**
     * @param Query $query
     * @return array<mixed>
     * @throws Exception
     */
    protected function buildFilter(Query $query): array
    {
        // Normalize extended ISO 8601 datetime strings in query values to UTCDateTime
        // so they can be correctly compared against datetime fields stored in MongoDB.
        if (!$this->getSupportForAttributes() || \in_array($query->getAttribute(), ['$createdAt', '$updatedAt'], true)) {
            $values = $query->getValues();
            foreach ($values as $k => $value) {
                if (is_string($value) && $this->isExtendedISODatetime($value)) {
                    try {
                        $values[$k] = $this->toMongoDatetime($value);
                    } catch (\Throwable $th) {
                        // Leave value as-is if it cannot be parsed as a datetime
                    }
                }
            }
            $query->setValues($values);
        }

        if ($query->getAttribute() === '$id') {
            $query->setAttribute('_uid');
        } elseif ($query->getAttribute() === '$sequence') {
            $query->setAttribute('_id');
            $values = $query->getValues();
            foreach ($values as $k => $v) {
                $values[$k] = $v;
            }
            $query->setValues($values);
        } elseif ($query->getAttribute() === '$createdAt') {
            $query->setAttribute('_createdAt');
        } elseif ($query->getAttribute() === '$updatedAt') {
            $query->setAttribute('_updatedAt');
        } elseif (\str_starts_with($query->getAttribute(), '$')) {
            // Escape $ prefix and dots in user-defined $-prefixed attribute names for MongoDB
            $query->setAttribute($this->escapeMongoFieldName($query->getAttribute()));
        }

        $attribute = $query->getAttribute();
        $operator = $this->getQueryOperator($query->getMethod());

        $value = match ($query->getMethod()) {
            Query::TYPE_IS_NULL,
            Query::TYPE_IS_NOT_NULL => null,
            Query::TYPE_EXISTS => true,
            Query::TYPE_NOT_EXISTS => false,
            default => $this->getQueryValue(
                $query->getMethod(),
                count($query->getValues()) > 1
                    ? $query->getValues()
                    : $query->getValues()[0]
            ),
        };

        $filter = [];
        if ($query->isObjectAttribute() && !\str_contains($attribute, '.') && in_array($query->getMethod(), [Query::TYPE_EQUAL, Query::TYPE_CONTAINS, Query::TYPE_CONTAINS_ANY, Query::TYPE_CONTAINS_ALL, Query::TYPE_NOT_CONTAINS, Query::TYPE_NOT_EQUAL])) {
            $this->handleObjectFilters($query, $filter);
            return $filter;
        }

        if ($operator == '$eq' && \is_array($value)) {
            $filter[$attribute]['$in'] = $value;
        } elseif ($operator == '$ne' && \is_array($value)) {
            $filter[$attribute]['$nin'] = $value;
        } elseif ($operator == '$all') {
            $filter[$attribute]['$all'] = $query->getValues();
        } elseif ($operator == '$in') {
            if (in_array($query->getMethod(), [Query::TYPE_CONTAINS, Query::TYPE_CONTAINS_ANY]) && !$query->onArray()) {
                // contains support array values
                if (is_array($value)) {
                    $filter['$or'] = array_map(function ($val) use ($attribute) {
                        return [
                            $attribute => [
                                '$regex' => $this->createSafeRegex($val, '.*%s.*', 'i')
                            ]
                        ];
                    }, $value);
                } else {
                    $filter[$attribute]['$regex'] = $this->createSafeRegex($value, '.*%s.*');
                }
            } else {
                $filter[$attribute]['$in'] = $query->getValues();
            }
        } elseif ($operator === 'notContains') {
            if (!$query->onArray()) {
                $filter[$attribute] = ['$not' => $this->createSafeRegex($value, '.*%s.*')];
            } else {
                $filter[$attribute]['$nin'] = $query->getValues();
            }
        } elseif ($operator == '$search') {
            if ($query->getMethod() === Query::TYPE_NOT_SEARCH) {
                // MongoDB doesn't support negating $text expressions directly
                // Use regex as fallback for NOT search while keeping fulltext for positive search
                if (empty($value)) {
                    // If value is not passed, don't add any filter - this will match all documents
                } else {
                    $filter[$attribute] = ['$not' => $this->createSafeRegex($value, '.*%s.*')];
                }
            } else {
                $filter['$text'][$operator] = $value;
            }
        } elseif ($operator === Query::TYPE_BETWEEN) {
            $filter[$attribute]['$lte'] = $value[1];
            $filter[$attribute]['$gte'] = $value[0];
        } elseif ($operator === Query::TYPE_NOT_BETWEEN) {
            $filter['$or'] = [
                [$attribute => ['$lt' => $value[0]]],
                [$attribute => ['$gt' => $value[1]]]
            ];
        } elseif ($operator === '$regex' && $query->getMethod() === Query::TYPE_NOT_STARTS_WITH) {
            $filter[$attribute] = ['$not' => $this->createSafeRegex($value, '^%s')];
        } elseif ($operator === '$regex' && $query->getMethod() === Query::TYPE_NOT_ENDS_WITH) {
            $filter[$attribute] = ['$not' => $this->createSafeRegex($value, '%s$')];
        } elseif ($operator === '$exists') {
            foreach ($query->getValues() as $attribute) {
                $filter['$or'][] = [$attribute => [$operator => $value]];
            }
        } else {
            $filter[$attribute][$operator] = $value;
        }

        return $filter;
    }

    /**
     * @param Query $query
     * @param array<string, mixed> $filter
     * @return void
     */
    private function handleObjectFilters(Query $query, array &$filter): void
    {
        $conditions = [];
        $isNot = in_array($query->getMethod(), [Query::TYPE_NOT_CONTAINS,Query::TYPE_NOT_EQUAL]);
        $values = $query->getValues();
        foreach ($values as $attribute => $value) {
            $flattendQuery = $this->flattenWithDotNotation(is_string($attribute) ? $attribute : '', $value);
            $flattenedObjectKey = array_key_first($flattendQuery);
            $queryValue = $flattendQuery[$flattenedObjectKey];
            $queryAttribute = $query->getAttribute();
            $flattenedQueryField = array_key_first($flattendQuery);
            $flattenedObjectKey = $flattenedQueryField === '' ? $queryAttribute : $queryAttribute . '.' . array_key_first($flattendQuery);
            switch ($query->getMethod()) {

                case Query::TYPE_CONTAINS:
                case Query::TYPE_CONTAINS_ANY:
                case Query::TYPE_CONTAINS_ALL:
                case Query::TYPE_NOT_CONTAINS: {
                    $arrayValue = \is_array($queryValue) ? $queryValue : [$queryValue];
                    $operator = $isNot ? '$nin' : '$in';
                    $conditions[] = [ $flattenedObjectKey => [ $operator => $arrayValue] ];
                    break;
                }

                case Query::TYPE_EQUAL:
                case Query::TYPE_NOT_EQUAL: {
                    if (\is_array($queryValue)) {
                        $operator = $isNot ? '$nin' : '$in';
                        $conditions[] = [ $flattenedObjectKey => [ $operator => $queryValue] ];
                    } else {
                        $operator = $isNot ? '$ne' : '$eq';
                        $conditions[] = [ $flattenedObjectKey => [ $operator => $queryValue] ];
                    }

                    break;
                }
            }
        }

        $logicalOperator = $isNot ? '$and' : '$or';
        if (count($conditions) && isset($filter[$logicalOperator])) {
            $filter[$logicalOperator] = array_merge($filter[$logicalOperator], $conditions);
        } else {
            $filter[$logicalOperator] = $conditions;
        }
    }

    /**
     * Flatten a nested associative array into Mongo-style dot notation.
     *
     * @param string $key
     * @param mixed $value
     * @param string $prefix
     * @return array<string, mixed>
     */
    private function flattenWithDotNotation(string $key, mixed $value, string $prefix = ''): array
    {
        /** @var array<string, mixed> $result */
        $result = [];

        $stack = [];

        $initialKey = $prefix === '' ? $key : $prefix . '.' . $key;
        $stack[] = [$initialKey, $value];
        while (!empty($stack)) {
            [$currentPath, $currentValue] = array_pop($stack);
            if (is_array($currentValue) && !array_is_list($currentValue)) {
                foreach ($currentValue as $nextKey => $nextValue) {
                    $nextKey = (string)$nextKey;
                    $nextPath = $currentPath === '' ? $nextKey : $currentPath . '.' . $nextKey;
                    $stack[] = [$nextPath,  $nextValue];
                }
            } else {
                // leaf node
                $result[$currentPath] = $currentValue;
            }
        }

        return $result;
    }

    /**
     * Get Query Operator
     *
     * @param string $operator
     *
     * @return string
     * @throws Exception
     */
    protected function getQueryOperator(string $operator): string
    {
        return match ($operator) {
            Query::TYPE_EQUAL,
            Query::TYPE_IS_NULL => '$eq',
            Query::TYPE_NOT_EQUAL,
            Query::TYPE_IS_NOT_NULL => '$ne',
            Query::TYPE_LESSER => '$lt',
            Query::TYPE_LESSER_EQUAL => '$lte',
            Query::TYPE_GREATER => '$gt',
            Query::TYPE_GREATER_EQUAL => '$gte',
            Query::TYPE_CONTAINS => '$in',
            Query::TYPE_CONTAINS_ANY => '$in',
            Query::TYPE_CONTAINS_ALL => '$all',
            Query::TYPE_NOT_CONTAINS => 'notContains',
            Query::TYPE_SEARCH => '$search',
            Query::TYPE_NOT_SEARCH => '$search',
            Query::TYPE_BETWEEN => 'between',
            Query::TYPE_NOT_BETWEEN => 'notBetween',
            Query::TYPE_STARTS_WITH,
            Query::TYPE_NOT_STARTS_WITH,
            Query::TYPE_ENDS_WITH,
            Query::TYPE_NOT_ENDS_WITH,
            Query::TYPE_REGEX => '$regex',
            Query::TYPE_OR => '$or',
            Query::TYPE_AND => '$and',
            Query::TYPE_EXISTS,
            Query::TYPE_NOT_EXISTS => '$exists',
            Query::TYPE_ELEM_MATCH => '$elemMatch',
            default => throw new DatabaseException('Unknown operator:' . $operator . '. Must be one of ' . Query::TYPE_EQUAL . ', ' . Query::TYPE_NOT_EQUAL . ', ' . Query::TYPE_LESSER . ', ' . Query::TYPE_LESSER_EQUAL . ', ' . Query::TYPE_GREATER . ', ' . Query::TYPE_GREATER_EQUAL . ', ' . Query::TYPE_IS_NULL . ', ' . Query::TYPE_IS_NOT_NULL . ', ' . Query::TYPE_BETWEEN . ', ' . Query::TYPE_NOT_BETWEEN . ', ' . Query::TYPE_STARTS_WITH . ', ' . Query::TYPE_NOT_STARTS_WITH . ', ' . Query::TYPE_ENDS_WITH . ', ' . Query::TYPE_NOT_ENDS_WITH . ', ' . Query::TYPE_CONTAINS . ', ' . Query::TYPE_NOT_CONTAINS . ', ' . Query::TYPE_SEARCH . ', ' . Query::TYPE_NOT_SEARCH . ', ' . Query::TYPE_SELECT),
        };
    }

    protected function getQueryValue(string $method, mixed $value): mixed
    {
        switch ($method) {
            case Query::TYPE_STARTS_WITH:
                $value = preg_quote($value, '/');
                return $value . '.*';
            case Query::TYPE_NOT_STARTS_WITH:
                return $value;
            case Query::TYPE_ENDS_WITH:
                $value = preg_quote($value, '/');
                return '.*' . $value;
            case Query::TYPE_NOT_ENDS_WITH:
                return $value;
            default:
                return $value;
        }
    }

    /**
     * Get Mongo Order
     *
     * @param string $order
     *
     * @return int
     * @throws Exception
     */
    protected function getOrder(string $order): int
    {
        return match (\strtoupper($order)) {
            Database::ORDER_ASC => 1,
            Database::ORDER_DESC => -1,
            default => throw new DatabaseException('Unknown sort order:' . $order . '. Must be one of ' . Database::ORDER_ASC . ', ' . Database::ORDER_DESC),
        };
    }

    /**
     * Check if tenant should be added to index
     *
     * @param Document|string $indexOrType Index document or index type string
     * @return bool
     */
    protected function shouldAddTenantToIndex(Document|string $indexOrType): bool
    {
        if (!$this->sharedTables) {
            return false;
        }

        $indexType = $indexOrType instanceof Document
            ? $indexOrType->getAttribute('type')
            : $indexOrType;

        return $indexType !== Database::INDEX_TTL;
    }

    /**
     * @param array<string> $selections
     * @param string $prefix
     * @return mixed
     */
    protected function getAttributeProjection(array $selections, string $prefix = ''): mixed
    {
        $projection = [];

        $internalKeys = \array_map(
            fn ($attr) => $attr['$id'],
            Database::INTERNAL_ATTRIBUTES
        );

        foreach ($selections as $selection) {
            // Skip internal attributes since all are selected by default
            if (\in_array($selection, $internalKeys)) {
                continue;
            }

            $projection[$selection] = 1;
        }

        $projection['_uid'] = 1;
        $projection['_id'] = 1;
        $projection['_createdAt'] = 1;
        $projection['_updatedAt'] = 1;
        $projection['_permissions'] = 1;

        return $projection;
    }

    /**
     * Get max STRING limit
     *
     * @return int
     */
    public function getLimitForString(): int
    {
        return 2147483647;
    }

    /**
     * Get max VARCHAR limit
     * MongoDB doesn't distinguish between string types, so using same as string limit
     *
     * @return int
     */
    public function getMaxVarcharLength(): int
    {
        return 2147483647;
    }

    /**
     * Get max INT limit
     *
     * @return int
     */
    public function getLimitForInt(): int
    {
        // Mongo does not handle integers directly, so using MariaDB limit for now
        return 4294967295;
    }

    /**
     * Get max BIGINT limit
     *
     * @return int
     */
    public function getLimitForBigInt(): int
    {
        return Database::MAX_BIG_INT;
    }

    /**
     * Get maximum column limit.
     * Returns 0 to indicate no limit
     *
     * @return int
     */
    public function getLimitForAttributes(): int
    {
        return 0;
    }

    /**
     * Get maximum index limit.
     * https://docs.mongodb.com/manual/reference/limits/#mongodb-limit-Number-of-Indexes-per-Collection
     *
     * @return int
     */
    public function getLimitForIndexes(): int
    {
        return 64;
    }

    public function getMinDateTime(): \DateTime
    {
        return new \DateTime('-9999-01-01 00:00:00');
    }

    /**
     * Is schemas supported?
     *
     * @return bool
     */
    public function getSupportForSchemas(): bool
    {
        return false;
    }

    /**
     * Is index supported?
     *
     * @return bool
     */
    public function getSupportForIndex(): bool
    {
        return true;
    }

    public function getSupportForIndexArray(): bool
    {
        return true;
    }

    /**
     * Is internal casting supported?
     *
     * @return bool
     */
    public function getSupportForInternalCasting(): bool
    {
        return true;
    }

    public function getSupportForUTCCasting(): bool
    {
        return true;
    }

    public function setUTCDatetime(string $value): mixed
    {
        return new UTCDateTime(new \DateTime($value));
    }


    /**
     * Are attributes supported?
     *
     * @return bool
     */
    public function getSupportForAttributes(): bool
    {
        return $this->supportForAttributes;
    }

    public function setSupportForAttributes(bool $support): bool
    {
        $this->supportForAttributes = $support;
        return $this->supportForAttributes;
    }

    /**
     * Is unique index supported?
     *
     * @return bool
     */
    public function getSupportForUniqueIndex(): bool
    {
        return true;
    }

    /**
     * Is fulltext index supported?
     *
     * @return bool
     */
    public function getSupportForFulltextIndex(): bool
    {
        return true;
    }

    /**
     * Is fulltext Wildcard index supported?
     *
     * @return bool
     */
    public function getSupportForFulltextWildcardIndex(): bool
    {
        return false;
    }

    /**
     * Does the adapter handle Query Array Contains?
     *
     * @return bool
     */
    public function getSupportForQueryContains(): bool
    {
        return false;
    }

    /**
     * Are timeouts supported?
     *
     * @return bool
     */
    public function getSupportForTimeouts(): bool
    {
        return true;
    }

    public function getSupportForRelationships(): bool
    {
        return true;
    }

    public function getSupportForUpdateLock(): bool
    {
        return false;
    }

    public function getSupportForAttributeResizing(): bool
    {
        return false;
    }

    /**
     * Are batch operations supported?
     *
     * @return bool
     */
    public function getSupportForBatchOperations(): bool
    {
        return false;
    }

    /**
     * Is get connection id supported?
     *
     * @return bool
     */
    public function getSupportForGetConnectionId(): bool
    {
        return false;
    }

    /**
     * Is PCRE regex supported?
     *
     * @return bool
     */
    public function getSupportForPCRERegex(): bool
    {
        return true;
    }

    /**
     * Is POSIX regex supported?
     *
     * @return bool
     */
    public function getSupportForPOSIXRegex(): bool
    {
        return false;
    }

    /**
     * Is cache fallback supported?
     *
     * @return bool
     */
    public function getSupportForCacheSkipOnFailure(): bool
    {
        return false;
    }

    public function getSupportForCaching(): bool
    {
        return true;
    }

    /**
     * Is hostname supported?
     *
     * @return bool
     */
    public function getSupportForHostname(): bool
    {
        return true;
    }

    /**
     * Is get schema attributes supported?
     *
     * @return bool
     */
    public function getSupportForSchemaAttributes(): bool
    {
        return false;
    }

    public function getSupportForCastIndexArray(): bool
    {
        return false;
    }

    public function getSupportForUpserts(): bool
    {
        return true;
    }

    public function getSupportForUpsertOnUniqueIndex(): bool
    {
        return false;
    }

    public function getSupportForReconnection(): bool
    {
        return false;
    }

    public function getSupportForBatchCreateAttributes(): bool
    {
        return true;
    }

    public function getSupportForObject(): bool
    {
        return true;
    }

    /**
     * Are object (JSON) indexes supported?
     *
     * @return bool
     */
    public function getSupportForObjectIndexes(): bool
    {
        return false;
    }

    /**
     * Get current attribute count from collection document
     *
     * @param Document $collection
     * @return int
     */
    public function getCountOfAttributes(Document $collection): int
    {
        $attributes = \count($collection->getAttribute('attributes') ?? []);

        return $attributes + static::getCountOfDefaultAttributes();
    }

    /**
     * Get current index count from collection document
     *
     * @param Document $collection
     * @return int
     */
    public function getCountOfIndexes(Document $collection): int
    {
        $indexes = \count($collection->getAttribute('indexes') ?? []);

        return $indexes + static::getCountOfDefaultIndexes();
    }

    /**
     * Returns number of attributes used by default.
     *p
     * @return int
     */
    public function getCountOfDefaultAttributes(): int
    {
        return \count(Database::INTERNAL_ATTRIBUTES);
    }

    /**
     * Returns number of indexes used by default.
     *
     * @return int
     */
    public function getCountOfDefaultIndexes(): int
    {
        return \count(Database::INTERNAL_INDEXES);
    }

    /**
     * Get maximum width, in bytes, allowed for a SQL row
     * Return 0 when no restrictions apply
     *
     * @return int
     */
    public function getDocumentSizeLimit(): int
    {
        return 0;
    }

    /**
     * Estimate maximum number of bytes required to store a document in $collection.
     * Byte requirement varies based on column type and size.
     * Needed to satisfy MariaDB/MySQL row width limit.
     * Return 0 when no restrictions apply to row width
     *
     * @param Document $collection
     * @return int
     */
    public function getAttributeWidth(Document $collection): int
    {
        return 0;
    }

    /**
     * Is casting supported?
     *
     * @return bool
     */
    public function getSupportForCasting(): bool
    {
        return false;
    }

    /**
     * Is spatial attributes supported?
     *
     * @return bool
     */
    public function getSupportForSpatialAttributes(): bool
    {
        return false;
    }

    /**
     * Get Support for Null Values in Spatial Indexes
     *
     * @return bool
     */
    public function getSupportForSpatialIndexNull(): bool
    {
        return false;
    }

    /**
     * Does the adapter support operators?
     *
     * @return bool
     */
    public function getSupportForOperators(): bool
    {
        return true;
    }

    /**
     * Does the adapter require booleans to be converted to integers (0/1)?
     *
     * @return bool
     */
    public function getSupportForIntegerBooleans(): bool
    {
        return false;
    }

    /**
     * Does the adapter includes boundary during spatial contains?
     *
     * @return bool
     */

    public function getSupportForBoundaryInclusiveContains(): bool
    {
        return false;
    }

    /**
     * Does the adapter support order attribute in spatial indexes?
     *
     * @return bool
     */
    public function getSupportForSpatialIndexOrder(): bool
    {
        return false;
    }


    /**
     * Does the adapter support spatial axis order specification?
     *
     * @return bool
     */
    public function getSupportForSpatialAxisOrder(): bool
    {
        return false;
    }

    /**
     * Does the adapter support calculating distance(in meters) between multidimension geometry(line, polygon,etc)?
     *
     * @return bool
     */
    public function getSupportForDistanceBetweenMultiDimensionGeometryInMeters(): bool
    {
        return false;
    }

    public function getSupportForOptionalSpatialAttributeWithExistingRows(): bool
    {
        return false;
    }

    /**
     * Does the adapter support multiple fulltext indexes?
     *
     * @return bool
     */
    public function getSupportForMultipleFulltextIndexes(): bool
    {
        return false;
    }

    /**
     * Does the adapter support identical indexes?
     *
     * @return bool
     */
    public function getSupportForIdenticalIndexes(): bool
    {
        return false;
    }

    /**
     * Does the adapter support random order for queries?
     *
     * @return bool
     */
    public function getSupportForOrderRandom(): bool
    {
        return false;
    }

    public function getSupportForVectors(): bool
    {
        return false;
    }

    /**
     * Flattens the array.
     *
     * @param mixed $list
     * @return array<mixed>
     */
    protected function flattenArray(mixed $list): array
    {
        if (!is_array($list)) {
            // make sure the input is an array
            return array($list);
        }

        $newArray = [];

        foreach ($list as $value) {
            $newArray = array_merge($newArray, $this->flattenArray($value));
        }

        return $newArray;
    }

    /**
     * @param array<string, mixed>|Document $target
     * @return array<string, mixed>
     */
    protected function removeNullKeys(array|Document $target): array
    {
        $target = \is_array($target) ? $target : $target->getArrayCopy();
        $cleaned = [];

        foreach ($target as $key => $value) {
            if (\is_null($value)) {
                continue;
            }

            $cleaned[$key] = $value;
        }


        return $cleaned;
    }

    public function getKeywords(): array
    {
        return [];
    }

    protected function processException(\Throwable $e): \Throwable
    {
        // Timeout
        if ($e->getCode() === 50 || $e->getCode() === 262) {
            return new TimeoutException('Query timed out', $e->getCode(), $e);
        }

        // Duplicate key error
        if ($e->getCode() === 11000 || $e->getCode() === 11001) {
            $index = $this->getViolatedIndex($e->getMessage());
            if ($index !== null && $index !== '_uid' && $index !== '_id_') {
                return new UniqueException('Unique index violation', $e->getCode(), $e);
            }
            return new DuplicateException('Document already exists', $e->getCode(), $e);
        }

        // Collection already exists
        if ($e->getCode() === 48) {
            return new DuplicateException('Collection already exists', $e->getCode(), $e);
        }

        // Index already exists
        if ($e->getCode() === 85) {
            return new DuplicateException('Index already exists', $e->getCode(), $e);
        }

        // No transaction
        if ($e->getCode() === 251) {
            return new TransactionException('No active transaction', $e->getCode(), $e);
        }

        // Aborted transaction
        if ($e->getCode() === 112) {
            return new TransactionException('Transaction aborted', $e->getCode(), $e);
        }

        // Invalid operation (MongoDB error code 14)
        if ($e->getCode() === 14) {
            return new TypeException('Invalid operation', $e->getCode(), $e);
        }

        // Invalid $pow argument (0 raised to a negative power) — matches the SQL adapters, which
        // report an undefined power as a numeric range error.
        if ($e->getCode() === 28764) {
            return new LimitException('Value out of range', $e->getCode(), $e);
        }

        return $e;
    }

    /**
     * Extract the index name from a duplicate key error, e.g.
     * "E11000 duplicate key error collection: db.movies index: _uid dup key: { _uid: \"movie\" }"
     * resolves to "_uid". Returns null when the message cannot be parsed.
     */
    protected function getViolatedIndex(string $message): ?string
    {
        if (\preg_match('/index:\s*(\S+)\s+dup key/', $message, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    protected function quote(string $string): string
    {
        return "";
    }

    /**
     * @param mixed $stmt
     * @return bool
     */
    protected function execute(mixed $stmt): bool
    {
        return true;
    }

    /**
     * @return string
     */
    public function getIdAttributeType(): string
    {
        return Database::VAR_UUID7;
    }

    /**
     * @return int
     */
    public function getMaxIndexLength(): int
    {
        return 1024;
    }

    /**
     * @return int
     */
    public function getMaxUIDLength(): int
    {
        return 255;
    }

    public function getConnectionId(): string
    {
        return '0';
    }

    public function getInternalIndexesKeys(): array
    {
        return [];
    }

    public function getSchemaAttributes(string $collection): array
    {
        return [];
    }

    public function getSupportForSchemaIndexes(): bool
    {
        return false;
    }

    public function getSchemaIndexes(string $collection): array
    {
        return [];
    }

    /**
     * @param string $collection
     * @param array<int|string> $tenants
     * @return int|string|null|array<string, array<int|string|null>>
     */
    public function getTenantFilters(
        string $collection,
        array $tenants = [],
    ): int|string|null|array {
        $values = [];
        if (!$this->sharedTables) {
            return $values;
        }

        if (\count($tenants) === 0) {
            $values[] = $this->getTenant();
        } else {
            for ($index = 0; $index < \count($tenants); $index++) {
                $values[] = $tenants[$index];
            }
        }

        if ($collection === Database::METADATA) {
            $values[] = null;
        }

        if (\count($values) === 1) {
            return $values[0];
        }


        return ['$in' => $values];
    }

    public function decodePoint(string $wkb): array
    {
        return [];
    }

    /**
     * Decode a WKB or textual LINESTRING into [[x1, y1], [x2, y2], ...]
     *
     * @param string $wkb
     * @return float[][] Array of points, each as [x, y]
     */
    public function decodeLinestring(string $wkb): array
    {
        return [];
    }

    /**
     * Decode a WKB or textual POLYGON into [[[x1, y1], [x2, y2], ...], ...]
     *
     * @param string $wkb
     * @return float[][][] Array of rings, each ring is an array of points [x, y]
     */
    public function decodePolygon(string $wkb): array
    {
        return [];
    }

    /**
     * Get the query to check for tenant when in shared tables mode
     *
     * @param string $collection The collection being queried
     * @param string $alias The alias of the parent collection if in a subquery
     * @return string
     */
    public function getTenantQuery(string $collection, string $alias = ''): string
    {
        return '';
    }

    public function getSupportForAlterLocks(): bool
    {
        return false;
    }

    public function getSupportNonUtfCharacters(): bool
    {
        return false;
    }

    public function getSupportForTrigramIndex(): bool
    {
        return false;
    }

    public function getSupportForTTLIndexes(): bool
    {
        return true;
    }

    public function getSupportForTransactionRetries(): bool
    {
        return false;
    }

    public function getSupportForNestedTransactions(): bool
    {
        return false;
    }

    protected function isExtendedISODatetime(string $val): bool
    {
        /**
         * Min:
         *   YYYY-MM-DDTHH:mm:ssZ             (20)
         *   YYYY-MM-DDTHH:mm:ss+HH:MM        (25)
         *
         * Max:
         *   YYYY-MM-DDTHH:mm:ss.fffffZ       (26)
         *   YYYY-MM-DDTHH:mm:ss.fffff+HH:MM  (31)
         */

        $len = strlen($val);

        // absolute minimum
        if ($len < 20) {
            return false;
        }

        // fixed datetime fingerprints
        if (
            !isset($val[19]) ||
            $val[4]  !== '-' ||
            $val[7]  !== '-' ||
            $val[10] !== 'T' ||
            $val[13] !== ':' ||
            $val[16] !== ':'
        ) {
            return false;
        }

        // timezone detection
        $hasZ = ($val[$len - 1] === 'Z');

        $hasOffset = (
            $len >= 25 &&
            ($val[$len - 6] === '+' || $val[$len - 6] === '-') &&
            $val[$len - 3] === ':'
        );

        if (!$hasZ && !$hasOffset) {
            return false;
        }

        if ($hasOffset && $len > 31) {
            return false;
        }

        if ($hasZ && $len > 26) {
            return false;
        }

        $digitPositions = [
            0,1,2,3,
            5,6,
            8,9,
            11,12,
            14,15,
            17,18
        ];

        $timeEnd = $hasZ ? $len - 1 : $len - 6;

        // fractional seconds
        if ($timeEnd > 19) {
            if ($val[19] !== '.' || $timeEnd < 21) {
                return false;
            }
            for ($i = 20; $i < $timeEnd; $i++) {
                $digitPositions[] = $i;
            }
        }

        // timezone offset numeric digits
        if ($hasOffset) {
            foreach ([$len - 5, $len - 4, $len - 2, $len - 1] as $i) {
                $digitPositions[] = $i;
            }
        }

        foreach ($digitPositions as $i) {
            if (!ctype_digit($val[$i])) {
                return false;
            }
        }

        return true;
    }

    protected function convertUTCDateToString(mixed $node): mixed
    {
        if ($node instanceof UTCDateTime) {
            // Handle UTCDateTime objects
            $node = DateTime::format($node->toDateTime());
        } elseif (is_array($node) && isset($node['$date'])) {
            // Handle Extended JSON format from (array) cast
            // Format: {"$date":{"$numberLong":"1760405478290"}}
            if (is_array($node['$date']) && isset($node['$date']['$numberLong'])) {
                $milliseconds = (int)$node['$date']['$numberLong'];
                $seconds = intdiv($milliseconds, 1000);
                $microseconds = ($milliseconds % 1000) * 1000;
                $dateTime = \DateTime::createFromFormat('U.u', $seconds . '.' . str_pad((string)$microseconds, 6, '0'));
                if ($dateTime) {
                    $dateTime->setTimezone(new \DateTimeZone('UTC'));
                    $node = DateTime::format($dateTime);
                }
            }
        } elseif (is_string($node)) {
            // Already a string, validate and pass through
            try {
                new \DateTime($node);
            } catch (\Exception $e) {
                // Invalid date string, skip
            }
        }

        return $node;
    }
}
