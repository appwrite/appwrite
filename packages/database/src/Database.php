<?php

namespace Utopia\Database;

use Exception;
use Swoole\Coroutine;
use Throwable;
use Utopia\Cache\Cache;
use Utopia\Console;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Authorization as AuthorizationException;
use Utopia\Database\Exception\Conflict as ConflictException;
use Utopia\Database\Exception\Dependency as DependencyException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Index as IndexException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Order as OrderException;
use Utopia\Database\Exception\Query as QueryException;
use Utopia\Database\Exception\Relationship as RelationshipException;
use Utopia\Database\Exception\Restricted as RestrictedException;
use Utopia\Database\Exception\Structure as StructureException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Exception\Type as TypeException;
use Utopia\Database\Exception\Unique as UniqueException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Helpers\Permission;
use Utopia\Database\Helpers\Role;
use Utopia\Database\Validator\Attribute as AttributeValidator;
use Utopia\Database\Validator\Authorization;
use Utopia\Database\Validator\Authorization\Input;
use Utopia\Database\Validator\BigInt as BigIntValidator;
use Utopia\Database\Validator\Index as IndexValidator;
use Utopia\Database\Validator\IndexDependency as IndexDependencyValidator;
use Utopia\Database\Validator\PartialStructure;
use Utopia\Database\Validator\Permissions;
use Utopia\Database\Validator\Queries\Document as DocumentValidator;
use Utopia\Database\Validator\Queries\Documents as DocumentsValidator;
use Utopia\Database\Validator\Spatial;
use Utopia\Database\Validator\Structure;

class Database
{
    // Simple Types
    public const VAR_STRING = 'string';
    public const VAR_INTEGER = 'integer';
    public const VAR_BIGINT = 'bigint';
    public const VAR_FLOAT = 'double';
    public const VAR_BOOLEAN = 'boolean';
    public const VAR_DATETIME = 'datetime';

    public const VAR_VARCHAR = 'varchar';
    public const VAR_TEXT = 'text';
    public const VAR_MEDIUMTEXT = 'mediumtext';
    public const VAR_LONGTEXT = 'longtext';

    // ID types
    public const VAR_ID = 'id';
    public const VAR_UUID7 = 'uuid7';

    // object type
    public const VAR_OBJECT = 'object';

    // Vector types
    public const VAR_VECTOR = 'vector';

    // Vector query result key
    public const VECTOR_DISTANCE = '$distance';

    // Relationship Types
    public const VAR_RELATIONSHIP = 'relationship';

    // Spatial Types
    public const VAR_POINT = 'point';
    public const VAR_LINESTRING = 'linestring';
    public const VAR_POLYGON = 'polygon';

    // All string types
    public const STRING_TYPES = [
        self::VAR_STRING,
        self::VAR_VARCHAR,
        self::VAR_TEXT,
        self::VAR_MEDIUMTEXT,
        self::VAR_LONGTEXT,
    ];

    // All spatial types
    public const SPATIAL_TYPES = [
        self::VAR_POINT,
        self::VAR_LINESTRING,
        self::VAR_POLYGON
    ];

    // All types which requires filters
    public const ATTRIBUTE_FILTER_TYPES = [
        ...self::SPATIAL_TYPES,
        self::VAR_VECTOR,
        self::VAR_OBJECT,
        self::VAR_DATETIME
    ];

    // Index Types
    public const INDEX_KEY = 'key';
    public const INDEX_FULLTEXT = 'fulltext';
    public const INDEX_UNIQUE = 'unique';
    public const INDEX_SPATIAL = 'spatial';
    public const INDEX_OBJECT = 'object';
    public const INDEX_HNSW_EUCLIDEAN = 'hnsw_euclidean';
    public const INDEX_HNSW_COSINE = 'hnsw_cosine';
    public const INDEX_HNSW_DOT = 'hnsw_dot';
    public const INDEX_TRIGRAM = 'trigram';
    public const INDEX_TTL = 'ttl';

    // Max limits
    public const MAX_INT = 2147483647;
    public const MAX_BIG_INT = PHP_INT_MAX;
    public const MAX_DOUBLE = PHP_FLOAT_MAX;
    public const MAX_VECTOR_DIMENSIONS = 16000;
    public const MAX_ARRAY_INDEX_LENGTH = 255;
    public const MAX_UID_DEFAULT_LENGTH = 36;

    // Maximum byte capacity for TEXT
    public const MAX_TEXT_BYTES = 65535;
    public const MAX_MEDIUMTEXT_BYTES = 16777215;
    public const MAX_LONGTEXT_BYTES = 4294967295;

    // Min limits
    public const MIN_INT = -2147483648;

    // Global SRID for geographic coordinates (WGS84)
    public const DEFAULT_SRID = 4326;
    public const EARTH_RADIUS = 6371000;

    // Relation Types
    public const RELATION_ONE_TO_ONE = 'oneToOne';
    public const RELATION_ONE_TO_MANY = 'oneToMany';
    public const RELATION_MANY_TO_ONE = 'manyToOne';
    public const RELATION_MANY_TO_MANY = 'manyToMany';

    // Relation Actions
    public const RELATION_MUTATE_CASCADE = 'cascade';
    public const RELATION_MUTATE_RESTRICT = 'restrict';
    public const RELATION_MUTATE_SET_NULL = 'setNull';

    // Relation Sides
    public const RELATION_SIDE_PARENT = 'parent';
    public const RELATION_SIDE_CHILD = 'child';

    public const RELATION_MAX_DEPTH = 3;

    // Orders
    public const ORDER_ASC = 'ASC';
    public const ORDER_DESC = 'DESC';
    public const ORDER_RANDOM = 'RANDOM';

    // Permissions
    public const PERMISSION_CREATE = 'create';
    public const PERMISSION_READ = 'read';
    public const PERMISSION_UPDATE = 'update';
    public const PERMISSION_DELETE = 'delete';

    // Aggregate permissions
    public const PERMISSION_WRITE = 'write';

    public const PERMISSIONS = [
        self::PERMISSION_CREATE,
        self::PERMISSION_READ,
        self::PERMISSION_UPDATE,
        self::PERMISSION_DELETE,
    ];

    // Collections
    public const METADATA = '_metadata';

    // Cursor
    public const CURSOR_BEFORE = 'before';
    public const CURSOR_AFTER = 'after';

    // Lengths
    public const LENGTH_KEY = 255;

    // Cache
    public const TTL = 60 * 60 * 24; // 24 hours

    // Cache "Not Found" results
    private const CACHE_EMPTY_MARKER = '$empty';

    // Events
    public const EVENT_ALL = '*';

    public const EVENT_DATABASE_LIST = 'database_list';
    public const EVENT_DATABASE_CREATE = 'database_create';
    public const EVENT_DATABASE_DELETE = 'database_delete';

    public const EVENT_COLLECTION_LIST = 'collection_list';
    public const EVENT_COLLECTION_CREATE = 'collection_create';
    public const EVENT_COLLECTION_UPDATE = 'collection_update';
    public const EVENT_COLLECTION_READ = 'collection_read';
    public const EVENT_COLLECTION_DELETE = 'collection_delete';

    public const EVENT_DOCUMENT_FIND = 'document_find';
    public const EVENT_DOCUMENT_PURGE = 'document_purge';
    public const EVENT_DOCUMENT_CREATE = 'document_create';
    public const EVENT_DOCUMENTS_CREATE = 'documents_create';
    public const EVENT_DOCUMENT_READ = 'document_read';
    public const EVENT_DOCUMENT_UPDATE = 'document_update';
    public const EVENT_DOCUMENTS_UPDATE = 'documents_update';
    public const EVENT_DOCUMENTS_UPSERT = 'documents_upsert';
    public const EVENT_DOCUMENT_DELETE = 'document_delete';
    public const EVENT_DOCUMENTS_DELETE = 'documents_delete';
    public const EVENT_DOCUMENT_COUNT = 'document_count';
    public const EVENT_DOCUMENT_SUM = 'document_sum';
    public const EVENT_DOCUMENT_INCREASE = 'document_increase';
    public const EVENT_DOCUMENT_DECREASE = 'document_decrease';

    public const EVENT_PERMISSIONS_CREATE = 'permissions_create';
    public const EVENT_PERMISSIONS_READ = 'permissions_read';
    public const EVENT_PERMISSIONS_DELETE = 'permissions_delete';

    public const EVENT_ATTRIBUTE_CREATE = 'attribute_create';
    public const EVENT_ATTRIBUTES_CREATE = 'attributes_create';
    public const EVENT_ATTRIBUTE_UPDATE = 'attribute_update';
    public const EVENT_ATTRIBUTE_DELETE = 'attribute_delete';

    public const EVENT_INDEX_RENAME = 'index_rename';
    public const EVENT_INDEX_CREATE = 'index_create';
    public const EVENT_INDEX_DELETE = 'index_delete';

    public const INSERT_BATCH_SIZE = 1_000;
    public const DELETE_BATCH_SIZE = 1_000;

    /**
     * List of Internal attributes
     *
     * @var array<array<string, mixed>>
     */
    public const INTERNAL_ATTRIBUTES = [
        [
            '$id' => '$id',
            'type' => self::VAR_STRING,
            'size' => Database::LENGTH_KEY,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => '$sequence',
            'type' => self::VAR_ID,
            'size' => 0,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => '$collection',
            'type' => self::VAR_STRING,
            'size' => Database::LENGTH_KEY,
            'required' => true,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => '$tenant',
            'type' => self::VAR_ID,
            'size' => 0,
            'required' => false,
            'default' => null,
            'signed' => true,
            'array' => false,
            'filters' => [],
        ],
        [
            '$id' => '$createdAt',
            'type' => Database::VAR_DATETIME,
            'format' => '',
            'size' => 0,
            'signed' => false,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => ['datetime']
        ],
        [
            '$id' => '$updatedAt',
            'type' => Database::VAR_DATETIME,
            'format' => '',
            'size' => 0,
            'signed' => false,
            'required' => false,
            'default' => null,
            'array' => false,
            'filters' => ['datetime']
        ],
        [
            '$id' => '$permissions',
            'type' => Database::VAR_STRING,
            'size' => 1_000_000,
            'signed' => true,
            'required' => false,
            'default' => [],
            'array' => false,
            'filters' => ['json']
        ],
    ];

    public const INTERNAL_ATTRIBUTE_KEYS = [
        '_uid',
        '_createdAt',
        '_updatedAt',
        '_permissions',
    ];

    public const INTERNAL_INDEXES = [
        '_id',
        '_uid',
        '_createdAt',
        '_updatedAt',
        '_permissions_id',
        '_permissions',
    ];

    /**
     * Parent Collection
     * Defines the structure for both system and custom collections
     *
     * @var array<string, mixed>
     */
    protected const COLLECTION = [
        '$id' => self::METADATA,
        '$collection' => self::METADATA,
        'name' => 'collections',
        'attributes' => [
            [
                '$id' => 'name',
                'key' => 'name',
                'type' => self::VAR_STRING,
                'size' => 256,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => [],
            ],
            [
                '$id' => 'attributes',
                'key' => 'attributes',
                'type' => self::VAR_STRING,
                'size' => 1000000,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => ['json'],
            ],
            [
                '$id' => 'indexes',
                'key' => 'indexes',
                'type' => self::VAR_STRING,
                'size' => 1000000,
                'required' => false,
                'signed' => true,
                'array' => false,
                'filters' => ['json'],
            ],
            [
                '$id' => 'documentSecurity',
                'key' => 'documentSecurity',
                'type' => self::VAR_BOOLEAN,
                'size' => 0,
                'required' => true,
                'signed' => true,
                'array' => false,
                'filters' => []
            ]
        ],
        'indexes' => [],
    ];

    protected Adapter $adapter;

    protected Cache $cache;

    protected string $cacheName = 'default';

    /**
     * @var array<string, array{encode: callable, decode: callable, signature: string}>
     */
    protected static array $filters = [];

    protected static bool $defaultFiltersRegistered = false;

    protected static int $filtersVersion = 0;

    /**
     * @var array<array<string, mixed>>|null
     */
    private static ?array $tenantlessInternalAttributes = null;

    /**
     * @var array<string, array{encode: callable, decode: callable, signature: string}>
     */
    protected array $instanceFilters = [];

    /**
     * @var array<string, string>
     */
    private array $filterSignatures = [];

    private string $filterSignaturesEncoded = '';

    private int $filterSignaturesVersion = -1;

    /**
     * @var array<string, array{encode: callable, decode: callable, signature: string}>
     */
    private array $filterSignaturesSource = [];

    /**
     * @var array<string, array<string, callable>>
     */
    protected array $listeners = [
        '*' => [],
    ];

    /**
     * Array in which the keys are the names of database listeners that
     * should be skipped when dispatching events. null $silentListeners
     * will skip all listeners.
     *
     * @var ?array<string, bool>
     */
    protected ?array $silentListeners = [];

    protected ?\DateTime $timestamp = null;

    protected bool $resolveRelationships = true;

    protected bool $checkRelationshipsExist = true;

    protected int $relationshipFetchDepth = 0;

    protected bool $inBatchRelationshipPopulation = false;

    protected bool $filter = true;

    /**
     * @var array<string, bool>|null
     */
    protected ?array $disabledFilters = [];

    protected bool $validate = true;

    protected bool $dropUnknownAttributes = false;

    protected bool $preserveDates = false;

    protected bool $skipDuplicates = false;

    protected bool $preserveSequence = false;

    protected int $maxQueryValues = 5000;

    protected bool $migrating = false;

    /**
     * List of collections that should be treated as globally accessible
     *
     * @var array<string, bool>
     */
    protected array $globalCollections = [];

    /**
     * Stack of collection IDs when creating or updating related documents
     * @var array<string>
     */
    protected array $relationshipWriteStack = [];

    /**
     * @var array<Document>
     */
    protected array $relationshipFetchStack = [];

    /**
     * @var array<Document>
     */
    protected array $relationshipDeleteStack = [];

    /**
     * Type mapping for collections to custom document classes
     * @var array<string, class-string<Document>>
     */
    protected array $documentTypes = [];


    /**
     * @var Authorization
     */
    private Authorization $authorization;

    /**
     * @param Adapter $adapter
     * @param Cache $cache
     * @param array<string, array{encode: callable, decode: callable}> $filters
     */
    public function __construct(
        Adapter $adapter,
        Cache $cache,
        array $filters = []
    ) {
        $this->adapter = $adapter;
        $this->cache = $cache;
        foreach ($filters as $name => $callbacks) {
            $filters[$name]['signature'] = self::computeCallableSignature($callbacks['encode'])
                . ':' . self::computeCallableSignature($callbacks['decode']);
        }
        $this->instanceFilters = $filters;

        $this->setAuthorization(new Authorization());

        self::registerDefaultFilters();
    }

    /**
     * Registers the built-in filters on first touch of the registry, so an
     * explicit addFilter() always wins regardless of whether it ran before or
     * after the first instance. The flag is set first: addFilter() calls back
     * into this, and the guard is what terminates that recursion.
     */
    private static function registerDefaultFilters(): void
    {
        if (self::$defaultFiltersRegistered) {
            return;
        }

        self::$defaultFiltersRegistered = true;

        self::addFilter(
            'json',
            /**
             * @param mixed $value
             * @return mixed
             */
            static function (mixed $value) {
                $value = ($value instanceof Document) ? $value->getArrayCopy() : $value;

                if (!is_array($value) && !$value instanceof \stdClass) {
                    return $value;
                }

                return json_encode($value);
            },
            /**
             * @param mixed $value
             * @return mixed
             * @throws Exception
             */
            static function (mixed $value) {
                if (!is_string($value)) {
                    return $value;
                }

                $value = json_decode($value, true) ?? [];

                if (array_key_exists('$id', $value)) {
                    return new Document($value);
                } else {
                    $value = array_map(static function ($item) {
                        if (is_array($item) && array_key_exists('$id', $item)) { // if `$id` exists, create a Document instance
                            return new Document($item);
                        }
                        return $item;
                    }, $value);
                }

                return $value;
            }
        );

        self::addFilter(
            'datetime',
            /**
             * @param mixed $value
             * @return mixed
             */
            static function (mixed $value) {
                if (is_null($value)) {
                    return;
                }
                try {
                    $value = new \DateTime($value);
                    $value->setTimezone(new \DateTimeZone(date_default_timezone_get()));
                    return DateTime::format($value);
                } catch (\Throwable) {
                    return $value;
                }
            },
            /**
             * @param string|null $value
             * @return string|null
             */
            static function (?string $value) {
                return DateTime::formatTz($value);
            }
        );

        self::addFilter(
            Database::VAR_POINT,
            /**
             * @param mixed $value
             * @param Document $document
             * @param Database $database
             * @return mixed
             */
            static function (mixed $value, Document $document, Database $database) {
                if (!is_array($value)) {
                    return $value;
                }
                try {
                    return $database->encodeSpatialData($value, Database::VAR_POINT);
                } catch (\Throwable) {
                    return $value;
                }
            },
            /**
             * @param string|null $value
             * @param Document $document
             * @param Database $database
             * @return array|null
             */
            static function (?string $value, Document $document, Database $database) {
                if ($value === null) {
                    return null;
                }
                return $database->adapter->decodePoint($value);
            }
        );

        self::addFilter(
            Database::VAR_LINESTRING,
            /**
             * @param mixed $value
             * @param Document $document
             * @param Database $database
             * @return mixed
             */
            static function (mixed $value, Document $document, Database $database) {
                if (!is_array($value)) {
                    return $value;
                }
                try {
                    return $database->encodeSpatialData($value, Database::VAR_LINESTRING);
                } catch (\Throwable) {
                    return $value;
                }
            },
            /**
             * @param string|null $value
             * @param Document $document
             * @param Database $database
             * @return array|null
             */
            static function (?string $value, Document $document, Database $database) {
                if (is_null($value)) {
                    return null;
                }
                return $database->adapter->decodeLinestring($value);
            }
        );

        self::addFilter(
            Database::VAR_POLYGON,
            /**
             * @param mixed $value
             * @param Document $document
             * @param Database $database
             * @return mixed
             */
            static function (mixed $value, Document $document, Database $database) {
                if (!is_array($value)) {
                    return $value;
                }
                try {
                    return $database->encodeSpatialData($value, Database::VAR_POLYGON);
                } catch (\Throwable) {
                    return $value;
                }
            },
            /**
             * @param string|null $value
             * @param Document $document
             * @param Database $database
             * @return array|null
             */
            static function (?string $value, Document $document, Database $database) {
                if (is_null($value)) {
                    return null;
                }
                return $database->adapter->decodePolygon($value);
            }
        );

        self::addFilter(
            Database::VAR_VECTOR,
            /**
             * @param mixed $value
             * @return mixed
             */
            static function (mixed $value) {
                if (!\is_array($value)) {
                    return $value;
                }
                if (!\array_is_list($value)) {
                    return $value;
                }
                foreach ($value as $item) {
                    if (!\is_int($item) && !\is_float($item)) {
                        return $value;
                    }
                }

                return \json_encode(\array_map(\floatval(...), $value));
            },
            /**
             * @param string|null $value
             * @return mixed
             */
            static function (?string $value) {
                if (is_null($value)) {
                    return null;
                }
                if (!is_string($value)) {
                    return $value;
                }
                $decoded = json_decode($value, true);
                return is_array($decoded) ? $decoded : $value;
            }
        );

        self::addFilter(
            Database::VAR_OBJECT,
            /**
             * @param mixed $value
             * @return mixed
             */
            static function (mixed $value) {
                if (!\is_array($value) && !$value instanceof \stdClass) {
                    return $value;
                }

                return \json_encode($value);
            },
            /**
             * @param mixed $value
             * @return array|null
             */
            static function (mixed $value) {
                if (is_null($value)) {
                    return;
                }
                // can be non string in case of mongodb as it stores the value as object
                if (!is_string($value)) {
                    return $value;
                }
                $decoded = self::decodeObject($value);

                return is_array($decoded) || $decoded instanceof \stdClass ? $decoded : $value;
            }
        );
    }

    private static function decodeObject(string $value): mixed
    {
        if (preg_match('/\{\s*\}/', $value) === 0) {
            return json_decode($value, true);
        }

        return self::toAssociative(json_decode($value));
    }

    private static function toAssociative(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = (array)$value;

            return $properties === [] ? $value : array_map(self::toAssociative(...), $properties);
        }

        if (is_array($value)) {
            return array_map(self::toAssociative(...), $value);
        }

        return $value;
    }

    private static function valuesEqual(mixed $value, mixed $old): bool
    {
        if ($value instanceof \stdClass && $old instanceof \stdClass) {
            return self::valuesEqual((array)$value, (array)$old);
        }

        if (is_array($value) && is_array($old)) {
            if (array_keys($value) !== array_keys($old)) {
                return false;
            }

            foreach ($value as $key => $item) {
                if (!self::valuesEqual($item, $old[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $value === $old;
    }

    /**
     * Add listener to events
     * Passing a null $callback will remove the listener
     *
     * @param string $event
     * @param string $name
     * @param ?callable $callback
     * @return static
     */
    public function on(string $event, string $name, ?callable $callback): static
    {
        if (empty($callback)) {
            unset($this->listeners[$event][$name]);
            return $this;
        }

        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][$name] = $callback;

        return $this;
    }

    /**
     * Add a transformation to be applied to a query string before an event occurs
     *
     * @param string $event
     * @param string $name
     * @param ?callable $callback
     * @return $this
     */
    public function before(string $event, string $name, ?callable $callback): static
    {
        $this->adapter->before($event, $name, $callback);

        return $this;
    }

    /**
     * Silent event generation for calls inside the callback
     *
     * @template T
     * @param callable(): T $callback
     * @param array<string>|null $listeners List of listeners to silence; if null, all listeners will be silenced
     * @return T
     */
    public function silent(callable $callback, ?array $listeners = null): mixed
    {
        $previous = $this->silentListeners;

        if (is_null($listeners)) {
            $this->silentListeners = null;
        } else {
            $silentListeners = [];
            foreach ($listeners as $listener) {
                $silentListeners[$listener] = true;
            }
            $this->silentListeners = $silentListeners;
        }

        try {
            return $callback();
        } finally {
            $this->silentListeners = $previous;
        }
    }

    /**
     * Get getConnection Id
     *
     * @return string
     * @throws Exception
     */
    public function getConnectionId(): string
    {
        return $this->adapter->getConnectionId();
    }

    /**
     * Skip relationships for all the calls inside the callback
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function skipRelationships(callable $callback): mixed
    {
        $previous = $this->resolveRelationships;
        $this->resolveRelationships = false;

        try {
            return $callback();
        } finally {
            $this->resolveRelationships = $previous;
        }
    }

    /**
     * Refetch documents after operator updates to get computed values
     *
     * @param Document $collection
     * @param array<Document> $documents
     * @param array<Query> $selections Select queries from the caller, preserved so the refetch honors the original projection
     * @return array<Document>
     * @throws DatabaseException
     */
    protected function refetchDocuments(Document $collection, array $documents, array $selections = []): array
    {
        if (empty($documents)) {
            return $documents;
        }

        $sequences = array_map(function ($doc) {
            $sequence = $doc->getSequence();
            if ($sequence === null) {
                throw new DatabaseException('Cannot refetch document without a $sequence: ' . $doc->getId());
            }
            return $sequence;
        }, $documents);

        // Fetch fresh copies with computed operator values, preserving the caller's projection.
        // Chunk by maxQueryValues (the batch can be up to INSERT_BATCH_SIZE) and bound each find()
        // to the chunk size, otherwise find()'s default limit would silently drop rows past it.
        $refetchedMap = [];
        foreach (\array_chunk($sequences, \max(1, $this->maxQueryValues)) as $chunk) {
            $refetched = $this->getAuthorization()->skip(fn () => $this->silent(
                fn () => $this->find(
                    $collection->getId(),
                    array_merge([
                        Query::equal('$sequence', $chunk),
                        Query::limit(\count($chunk)),
                    ], $selections)
                )
            ));

            foreach ($refetched as $doc) {
                $refetchedMap[$doc->getSequence()] = $doc;
            }
        }

        $result = [];
        foreach ($documents as $index => $doc) {
            $result[$index] = $refetchedMap[$sequences[$index]] ?? $doc;
        }

        return $result;
    }

    public function skipRelationshipsExistCheck(callable $callback): mixed
    {
        $previous = $this->checkRelationshipsExist;
        $this->checkRelationshipsExist = false;

        try {
            return $callback();
        } finally {
            $this->checkRelationshipsExist = $previous;
        }
    }

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
     * Build a tenant-aware identity key for a document.
     * Returns "<tenant>:<id>" in tenant-per-document shared-table mode, otherwise just the id.
     */
    private function tenantKey(Document $document): string
    {
        return ($this->adapter->getSharedTables() && $this->adapter->getTenantPerDocument())
            ? $document->getTenant() . ':' . $document->getId()
            : $document->getId();
    }

    /**
     * Trigger callback for events
     *
     * @param string $event
     * @param mixed $args
     * @return void
     */
    protected function trigger(string $event, mixed $args = null): void
    {
        if (\is_null($this->silentListeners)) {
            return;
        }
        foreach ($this->listeners[self::EVENT_ALL] as $name => $callback) {
            if (isset($this->silentListeners[$name])) {
                continue;
            }
            $callback($event, $args);
        }

        foreach (($this->listeners[$event] ?? []) as $name => $callback) {
            if (isset($this->silentListeners[$name])) {
                continue;
            }
            $callback($event, $args);
        }
    }

    /**
     * Executes $callback with $timestamp set to $requestTimestamp
     *
     * @template T
     * @param ?\DateTime $requestTimestamp
     * @param callable(): T $callback
     * @return T
     */
    public function withRequestTimestamp(?\DateTime $requestTimestamp, callable $callback): mixed
    {
        $previous = $this->timestamp;
        $this->timestamp = $requestTimestamp;
        try {
            $result = $callback();
        } finally {
            $this->timestamp = $previous;
        }
        return $result;
    }

    /**
     * Set Namespace.
     *
     * Set namespace to divide different scope of data sets
     *
     * @param string $namespace
     *
     * @return $this
     *
     * @throws DatabaseException
     */
    public function setNamespace(string $namespace): static
    {
        $this->adapter->setNamespace($namespace);

        return $this;
    }

    /**
     * Get Namespace.
     *
     * Get namespace of current set scope
     *
     * @return string
     */
    public function getNamespace(): string
    {
        return $this->adapter->getNamespace();
    }

    /**
     * Get ID Attribute Type.
     *
     * Returns the type of the internal ID attribute (e.g. VAR_INTEGER for SQL, VAR_UUID7 for MongoDB)
     *
     * @return string
     */
    public function getIdAttributeType(): string
    {
        return $this->adapter->getIdAttributeType();
    }

    /**
     * Set database to use for current scope
     *
     * @param string $name
     *
     * @return static
     * @throws DatabaseException
     */
    public function setDatabase(string $name): static
    {
        $this->adapter->setDatabase($name);

        return $this;
    }

    /**
     * Get Database.
     *
     * Get Database from current scope
     *
     * @return string
     * @throws DatabaseException
     */
    public function getDatabase(): string
    {
        return $this->adapter->getDatabase();
    }

    /**
     * Set the cache instance
     *
     * @param Cache $cache
     *
     * @return $this
     */
    public function setCache(Cache $cache): static
    {
        $this->cache = $cache;
        return $this;
    }

    /**
     * Get the cache instance
     *
     * @return Cache
     */
    public function getCache(): Cache
    {
        return $this->cache;
    }

    /**
     * Set the name to use for cache
     *
     * @param string $name
     * @return $this
     */
    public function setCacheName(string $name): static
    {
        $this->cacheName = $name;

        return $this;
    }

    /**
     * Get the cache name
     *
     * @return string
     */
    public function getCacheName(): string
    {
        return $this->cacheName;
    }

    /**
     * Set a metadata value to be printed in the query comments
     *
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function setMetadata(string $key, mixed $value): static
    {
        $this->adapter->setMetadata($key, $value);

        return $this;
    }

    /**
     * Get metadata
     *
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->adapter->getMetadata();
    }

    /**
     * Sets instance of authorization for permission checks
     *
     * @param Authorization $authorization
     * @return self
     */
    public function setAuthorization(Authorization $authorization): self
    {
        $this->adapter->setAuthorization($authorization);
        $this->authorization = $authorization;
        return $this;
    }

    /**
     * Get Authorization
     *
     * @return Authorization
     */
    public function getAuthorization(): Authorization
    {
        return $this->authorization;
    }

    /**
     * Clear metadata
     *
     * @return void
     */
    public function resetMetadata(): void
    {
        $this->adapter->resetMetadata();
    }

    /**
     * Set maximum query execution time
     *
     * @param int $milliseconds
     * @param string $event
     * @return static
     * @throws Exception
     */
    public function setTimeout(int $milliseconds, string $event = Database::EVENT_ALL): static
    {
        $this->adapter->setTimeout($milliseconds, $event);

        return $this;
    }

    /**
     * Clear maximum query execution time
     *
     * @param string $event
     * @return void
     */
    public function clearTimeout(string $event = Database::EVENT_ALL): void
    {
        $this->adapter->clearTimeout($event);
    }

    /**
     * Enable filters
     *
     * @return $this
     */
    public function enableFilters(): static
    {
        $this->filter = true;
        return $this;
    }

    /**
     * Disable filters
     *
     * @return $this
     */
    public function disableFilters(): static
    {
        $this->filter = false;
        return $this;
    }

    /**
     * Skip filters
     *
     * Execute a callback without filters
     *
     * @template T
     * @param callable(): T $callback
     * @param array<string>|null $filters
     * @return T
     */
    public function skipFilters(callable $callback, ?array $filters = null): mixed
    {
        if (empty($filters)) {
            $initial = $this->filter;
            $this->disableFilters();

            try {
                return $callback();
            } finally {
                $this->filter = $initial;
            }
        }

        $previous = $this->filter;
        $previousDisabled = $this->disabledFilters;
        $disabled = [];
        foreach ($filters as $name) {
            $disabled[$name] = true;
        }
        $this->disabledFilters = $disabled;

        try {
            return $callback();
        } finally {
            $this->filter = $previous;
            $this->disabledFilters = $previousDisabled;
        }
    }

    /**
     * Get instance filters
     *
     * @return array<string, array{encode: callable, decode: callable, signature: string}>
     */
    public function getInstanceFilters(): array
    {
        return $this->instanceFilters;
    }

    /**
     * Enable validation
     *
     * @return $this
     */
    public function enableValidation(): static
    {
        $this->validate = true;

        return $this;
    }

    /**
     * Disable validation
     *
     * @return $this
     */
    public function disableValidation(): static
    {
        $this->validate = false;

        return $this;
    }

    /**
     * Skip Validation
     *
     * Execute a callback without validation
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function skipValidation(callable $callback): mixed
    {
        $initial = $this->validate;
        $this->disableValidation();

        try {
            return $callback();
        } finally {
            $this->validate = $initial;
        }
    }

    /**
     * Get shared tables
     *
     * Get whether to share tables between tenants
     * @return bool
     */
    public function getSharedTables(): bool
    {
        return $this->adapter->getSharedTables();
    }

    /**
     * Set shard tables
     *
     * Set whether to share tables between tenants
     *
     * @param bool $sharedTables
     * @return static
     */
    public function setSharedTables(bool $sharedTables): static
    {
        $this->adapter->setSharedTables($sharedTables);

        return $this;
    }

    /**
     * Set Tenant
     *
     * Set tenant to use if tables are shared
     *
     * @param int|string|null $tenant
     * @return static
     */
    public function setTenant(int|string|null $tenant): static
    {
        $this->adapter->setTenant($tenant);

        return $this;
    }

    /**
     * Get Tenant
     *
     * Get tenant to use if tables are shared
     *
     * @return int|string|null
     */
    public function getTenant(): int|string|null
    {
        return $this->adapter->getTenant();
    }

    /**
     * With Tenant
     *
     * Execute a callback with a specific tenant
     *
     * @param int|string|null $tenant
     * @param callable $callback
     * @return mixed
     */
    public function withTenant(int|string|null $tenant, callable $callback): mixed
    {
        $previous = $this->adapter->getTenant();
        $this->adapter->setTenant($tenant);

        try {
            return $callback();
        } finally {
            $this->adapter->setTenant($previous);
        }
    }

    /**
     * Set whether to allow creating documents with tenant set per document.
     *
     * @param bool $enabled
     * @return static
     */
    public function setTenantPerDocument(bool $enabled): static
    {
        $this->adapter->setTenantPerDocument($enabled);

        return $this;
    }

    /**
     * Get whether to allow creating documents with tenant set per document.
     *
     * @return bool
     */
    public function getTenantPerDocument(): bool
    {
        return $this->adapter->getTenantPerDocument();
    }

    /**
     * Enable or disable LOCK=SHARED during ALTER TABLE operation
     *
     * Set lock mode when altering tables
     *
     * @param bool $enabled
     * @return static
     */
    public function enableLocks(bool $enabled): static
    {
        if ($this->adapter->getSupportForAlterLocks()) {
            $this->adapter->enableAlterLocks($enabled);
        }

        return $this;
    }

    /**
     * Set custom document class for a collection
     *
     * @param string $collection Collection ID
     * @param class-string<Document> $className Fully qualified class name that extends Document
     * @return static
     * @throws DatabaseException
     */
    public function setDocumentType(string $collection, string $className): static
    {
        if (!\class_exists($className)) {
            throw new DatabaseException("Class {$className} does not exist");
        }

        if (!\is_subclass_of($className, Document::class)) {
            throw new DatabaseException("Class {$className} must extend " . Document::class);
        }

        $this->documentTypes[$collection] = $className;

        return $this;
    }

    /**
     * Get custom document class for a collection
     *
     * @param string $collection Collection ID
     * @return class-string<Document>|null
     */
    public function getDocumentType(string $collection): ?string
    {
        return $this->documentTypes[$collection] ?? null;
    }

    /**
     * Clear document type mapping for a collection
     *
     * @param string $collection Collection ID
     * @return static
     */
    public function clearDocumentType(string $collection): static
    {
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
        $this->documentTypes = [];

        return $this;
    }

    /**
     * Create a document instance of the appropriate type
     *
     * @param string $collection Collection ID
     * @param array<string, mixed> $data Document data
     * @return Document
     */
    protected function createDocumentInstance(string $collection, array $data): Document
    {
        $className = $this->documentTypes[$collection] ?? Document::class;

        return new $className($data);
    }

    public function getDropUnknownAttributes(): bool
    {
        return $this->dropUnknownAttributes;
    }

    /**
     * Drop attributes missing from the collection schema instead of rejecting the write.
     *
     * Enable this where the schema is owned by the application rather than the caller, so a
     * deploy that writes an attribute before its migration has run degrades to a warning
     * instead of failing every write.
     */
    public function setDropUnknownAttributes(bool $drop): static
    {
        $this->dropUnknownAttributes = $drop;

        return $this;
    }

    public function getPreserveDates(): bool
    {
        return $this->preserveDates;
    }

    public function setPreserveDates(bool $preserve): static
    {
        $this->preserveDates = $preserve;

        return $this;
    }

    public function setMigrating(bool $migrating): self
    {
        $this->migrating = $migrating;

        return $this;
    }

    public function isMigrating(): bool
    {
        return $this->migrating;
    }

    public function withPreserveDates(callable $callback): mixed
    {
        $previous = $this->preserveDates;
        $this->preserveDates = true;

        try {
            return $callback();
        } finally {
            $this->preserveDates = $previous;
        }
    }

    public function getPreserveSequence(): bool
    {
        return $this->preserveSequence;
    }

    public function setPreserveSequence(bool $preserve): static
    {
        $this->preserveSequence = $preserve;

        return $this;
    }

    public function withPreserveSequence(callable $callback): mixed
    {
        $previous = $this->preserveSequence;
        $this->preserveSequence = true;

        try {
            return $callback();
        } finally {
            $this->preserveSequence = $previous;
        }
    }

    public function setMaxQueryValues(int $max): self
    {
        $this->maxQueryValues = $max;

        return $this;
    }

    public function getMaxQueryValues(): int
    {
        return $this->maxQueryValues;
    }

    /**
     * Set list of collections which are globally accessible
     *
     * @param array<string> $collections
     * @return $this
     */
    public function setGlobalCollections(array $collections): static
    {
        foreach ($collections as $collection) {
            $this->globalCollections[$collection] = true;
        }

        return $this;
    }

    /**
     * Get list of collections which are globally accessible
     *
     * @return array<string>
     */
    public function getGlobalCollections(): array
    {
        return \array_keys($this->globalCollections);
    }

    /**
     * Clear global collections
     *
     * @return void
     */
    public function resetGlobalCollections(): void
    {
        $this->globalCollections = [];
    }

    /**
     * Get list of keywords that cannot be used
     *
     * @return string[]
     */
    public function getKeywords(): array
    {
        return $this->adapter->getKeywords();
    }

    /**
     * Get Database Adapter
     *
     * @return Adapter
     */
    public function getAdapter(): Adapter
    {
        return $this->adapter;
    }

    /**
     * Run a callback inside a transaction.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     * @throws \Throwable
     */
    public function withTransaction(callable $callback): mixed
    {
        return $this->adapter->withTransaction($callback);
    }

    /**
     * Ping Database
     *
     * @return bool
     */
    public function ping(): bool
    {
        return $this->adapter->ping();
    }

    public function reconnect(): void
    {
        $this->adapter->reconnect();
    }

    /**
     * Create the database
     *
     * @param string|null $database
     * @return bool
     * @throws DuplicateException
     * @throws LimitException
     * @throws Exception
     */
    public function create(?string $database = null): bool
    {
        $database ??= $this->adapter->getDatabase();

        $this->adapter->create($database);

        /**
         * Create array of attribute documents
         * @var array<Document> $attributes
         */
        $attributes = \array_map(function ($attribute) {
            return new Document($attribute);
        }, self::COLLECTION['attributes']);

        $this->silent(fn () => $this->createCollection(self::METADATA, $attributes));

        try {
            $this->trigger(self::EVENT_DATABASE_CREATE, $database);
        } catch (\Throwable $e) {
            // Ignore
        }

        return true;
    }

    /**
     * Check if database exists
     * Optionally check if collection exists in database
     *
     * @param string|null $database (optional) database name
     * @param string|null $collection (optional) collection name
     *
     * @return bool
     */
    public function exists(?string $database = null, ?string $collection = null): bool
    {
        $database ??= $this->adapter->getDatabase();

        return $this->adapter->exists($database, $collection);
    }

    /**
     * List Databases
     *
     * @return array<Document>
     */
    public function list(): array
    {
        $databases = $this->adapter->list();

        try {
            $this->trigger(self::EVENT_DATABASE_LIST, $databases);
        } catch (\Throwable $e) {
            // Ignore
        }

        return $databases;
    }

    /**
     * Delete Database
     *
     * @param string|null $database
     * @return bool
     * @throws DatabaseException
     */
    public function delete(?string $database = null): bool
    {
        $database = $database ?? $this->adapter->getDatabase();

        $deleted = $this->adapter->delete($database);

        try {
            $this->trigger(self::EVENT_DATABASE_DELETE, [
                'name' => $database,
                'deleted' => $deleted
            ]);
        } catch (\Throwable $e) {
            // Ignore
        }

        $this->cache->flush();

        return $deleted;
    }

    /**
     * Create Collection
     *
     * @param string $id
     * @param array<Document> $attributes
     * @param array<Document> $indexes
     * @param array<string>|null $permissions
     * @param bool $documentSecurity
     * @return Document
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws LimitException
     */
    public function createCollection(string $id, array $attributes = [], array $indexes = [], ?array $permissions = null, bool $documentSecurity = true): Document
    {
        foreach ($attributes as &$attribute) {
            if (in_array($attribute['type'], self::ATTRIBUTE_FILTER_TYPES)) {
                $existingFilters = $attribute['filters'] ?? [];
                if (!is_array($existingFilters)) {
                    $existingFilters = [$existingFilters];
                }
                $attribute['filters'] = array_values(
                    array_unique(array_merge($existingFilters, [$attribute['type']]))
                );
            }
        }
        unset($attribute);

        $permissions ??= [
            Permission::create(Role::any()),
        ];

        if ($this->validate) {
            $validator = new Permissions();
            if (!$validator->isValid($permissions)) {
                throw new DatabaseException($validator->getDescription());
            }
        }

        $collection = $this->silent(fn () => $this->getCollection($id));

        if (!$collection->isEmpty() && $id !== self::METADATA) {
            throw new DuplicateException('Collection ' . $id . ' already exists');
        }

        // Enforce single TTL index per collection
        if ($this->validate && $this->getAdapter()->getSupportForTTLIndexes()) {
            $ttlIndexes = array_filter($indexes, fn (Document $idx) => $idx->getAttribute('type') === self::INDEX_TTL);
            if (count($ttlIndexes) > 1) {
                throw new IndexException('There can be only one TTL index in a collection');
            }
        }

        /**
         * Fix metadata index length & orders
         */
        foreach ($indexes as $key => $index) {
            $lengths = $index->getAttribute('lengths', []);
            $orders = $index->getAttribute('orders', []);

            foreach ($index->getAttribute('attributes', []) as $i => $attr) {
                foreach ($attributes as $collectionAttribute) {
                    if ($collectionAttribute->getAttribute('$id') === $attr) {
                        /**
                         * mysql does not save length in collection when length = attributes size
                         */
                        if (in_array($collectionAttribute->getAttribute('type'), self::STRING_TYPES)) {
                            if (!empty($lengths[$i]) && $lengths[$i] === $collectionAttribute->getAttribute('size') && $this->adapter->getMaxIndexLength() > 0) {
                                $lengths[$i] = null;
                            }
                        }

                        $isArray = $collectionAttribute->getAttribute('array', false);
                        if ($isArray) {
                            if ($this->adapter->getMaxIndexLength() > 0) {
                                $lengths[$i] = self::MAX_ARRAY_INDEX_LENGTH;
                            }
                            $orders[$i] = null;
                        }
                        break;
                    }
                }
            }

            $index->setAttribute('lengths', $lengths);
            $index->setAttribute('orders', $orders);
            $indexes[$key] = $index;
        }

        $collection = new Document([
            '$id' => ID::custom($id),
            '$permissions' => $permissions,
            'name' => $id,
            'attributes' => $attributes,
            'indexes' => $indexes,
            'documentSecurity' => $documentSecurity
        ]);

        if ($this->validate) {
            $validator = new IndexValidator(
                $attributes,
                [],
                $this->adapter->getMaxIndexLength(),
                $this->adapter->getInternalIndexesKeys(),
                $this->adapter->getSupportForIndexArray(),
                $this->adapter->getSupportForSpatialIndexNull(),
                $this->adapter->getSupportForSpatialIndexOrder(),
                $this->adapter->getSupportForVectors(),
                $this->adapter->getSupportForAttributes(),
                $this->adapter->getSupportForMultipleFulltextIndexes(),
                $this->adapter->getSupportForIdenticalIndexes(),
                $this->adapter->getSupportForObjectIndexes(),
                $this->adapter->getSupportForTrigramIndex(),
                $this->adapter->getSupportForSpatialAttributes(),
                $this->adapter->getSupportForIndex(),
                $this->adapter->getSupportForUniqueIndex(),
                $this->adapter->getSupportForFulltextIndex(),
                $this->adapter->getSupportForTTLIndexes(),
                $this->adapter->getSupportForObject()
            );
            foreach ($indexes as $index) {
                if (!$validator->isValid($index)) {
                    throw new IndexException($validator->getDescription());
                }
            }
        }

        // Check index limits, if given
        if ($indexes && $this->adapter->getCountOfIndexes($collection) > $this->adapter->getLimitForIndexes()) {
            throw new LimitException('Index limit of ' . $this->adapter->getLimitForIndexes() . ' exceeded. Cannot create collection.');
        }

        // Check attribute limits, if given
        if ($attributes) {
            if (
                $this->adapter->getLimitForAttributes() > 0 &&
                $this->adapter->getCountOfAttributes($collection) > $this->adapter->getLimitForAttributes()
            ) {
                throw new LimitException('Attribute limit of ' . $this->adapter->getLimitForAttributes() . ' exceeded. Cannot create collection.');
            }

            if (
                $this->adapter->getDocumentSizeLimit() > 0 &&
                $this->adapter->getAttributeWidth($collection) > $this->adapter->getDocumentSizeLimit()
            ) {
                throw new LimitException('Document size limit of ' . $this->adapter->getDocumentSizeLimit() . ' exceeded. Cannot create collection.');
            }
        }

        $createdPhysicalTable = false;

        try {
            $this->adapter->createCollection($id, $attributes, $indexes);
            $createdPhysicalTable = true;
        } catch (DuplicateException $e) {
            if ($id === self::METADATA
                || ($this->adapter->getSharedTables()
                    && $this->adapter->exists($this->adapter->getDatabase(), $id))) {
                // The metadata table must never be dropped during reconciliation.
                // In shared-tables mode the physical table is reused across
                // tenants. A DuplicateException simply means the table already
                // exists for another tenant — not an orphan.
            } else {
                // The table exists and this process did not create it. It may
                // belong to a peer that has not committed metadata yet, or it
                // may be an orphan. Dropping it destroyed live collections
                // during concurrent boot; attaching this caller's metadata to
                // an unknown physical schema can invent columns that are not
                // there. Leave the table and report Duplicate. Claiming the
                // metadata row first is #939.
                try {
                    $this->purgeCachedDocument(self::METADATA, $id);
                } catch (\Throwable $cacheError) {
                    Console::warning('Warning: Failed to purge stale collection cache: ' . $cacheError->getMessage());
                }
                throw new DuplicateException('Collection ' . $id . ' already exists', previous: $e);
            }
        }

        if ($id === self::METADATA) {
            return new Document(self::COLLECTION);
        }

        try {
            $createdCollection = $this->silent(fn () => $this->createDocument(self::METADATA, $collection));
        } catch (DuplicateException $e) {
            // A concurrent creator committed the metadata for this id first, so
            // the physical table is the one its metadata describes. Rolling back
            // here would drop a live collection out from under it.
            try {
                $this->purgeCachedDocument(self::METADATA, $id);
            } catch (\Throwable $cacheError) {
                Console::warning('Warning: Failed to purge stale collection cache: ' . $cacheError->getMessage());
            }
            throw new DuplicateException('Collection ' . $id . ' already exists', previous: $e);
        } catch (\Throwable $e) {
            if ($createdPhysicalTable) {
                try {
                    $this->cleanupCollection($id);
                } catch (\Throwable $e) {
                    Console::error("Failed to rollback collection '{$id}': " . $e->getMessage());
                }
            }
            throw new DatabaseException("Failed to create collection metadata for '{$id}': " . $e->getMessage(), previous: $e);
        }

        try {
            $this->trigger(self::EVENT_COLLECTION_CREATE, $createdCollection);
        } catch (\Throwable $e) {
            // Ignore
        }

        return $createdCollection;
    }

    /**
     * Update Collections Permissions.
     *
     * @param string $id
     * @param array<string> $permissions
     * @param bool $documentSecurity
     *
     * @return Document
     * @throws ConflictException
     * @throws DatabaseException
     */
    public function updateCollection(string $id, array $permissions, bool $documentSecurity): Document
    {
        if ($this->validate) {
            $validator = new Permissions();
            if (!$validator->isValid($permissions)) {
                throw new DatabaseException($validator->getDescription());
            }
        }

        $collection = $this->silent(fn () => $this->getCollection($id));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        if (
            $this->adapter->getSharedTables()
            && $collection->getTenant() != $this->adapter->getTenant()
        ) {
            throw new NotFoundException('Collection not found');
        }

        $collection
            ->setAttribute('$permissions', $permissions)
            ->setAttribute('documentSecurity', $documentSecurity);

        $collection = $this->silent(fn () => $this->updateDocument(self::METADATA, $collection->getId(), $collection));

        try {
            $this->trigger(self::EVENT_COLLECTION_UPDATE, $collection);
        } catch (\Throwable $e) {
            // Ignore
        }

        return $collection;
    }

    /**
     * Get Collection
     *
     * @param string $id
     *
     * @return Document
     * @throws DatabaseException
     */
    public function getCollection(string $id): Document
    {
        $collection = $this->silent(fn () => $this->getDocument(self::METADATA, $id));

        if (
            $id !== self::METADATA
            && $this->adapter->getSharedTables()
            && $collection->getTenant() !== null
            && $collection->getTenant() != $this->adapter->getTenant()
        ) {
            return new Document();
        }

        try {
            $this->trigger(self::EVENT_COLLECTION_READ, $collection);
        } catch (\Throwable $e) {
            // Ignore
        }

        return $collection;
    }

    /**
     * List Collections
     *
     * @param int $offset
     * @param int $limit
     *
     * @return array<Document>
     * @throws Exception
     */
    public function listCollections(int $limit = 25, int $offset = 0): array
    {
        $result = $this->silent(fn () => $this->find(self::METADATA, [
            Query::limit($limit),
            Query::offset($offset)
        ]));

        try {
            $this->trigger(self::EVENT_COLLECTION_LIST, $result);
        } catch (\Throwable $e) {
            // Ignore
        }

        return $result;
    }

    /**
     * Get Collection Size
     *
     * @param string $collection
     *
     * @return int
     * @throws Exception
     */
    public function getSizeOfCollection(string $collection): int
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        if ($this->adapter->getSharedTables() && $collection->getTenant() != $this->adapter->getTenant()) {
            throw new NotFoundException('Collection not found');
        }

        return $this->adapter->getSizeOfCollection($collection->getId());
    }

    /**
     * Get Collection Size on disk
     *
     * @param string $collection
     *
     * @return int
     */
    public function getSizeOfCollectionOnDisk(string $collection): int
    {
        if ($this->adapter->getSharedTables() && empty($this->adapter->getTenant())) {
            throw new DatabaseException('Missing tenant. Tenant must be set when table sharing is enabled.');
        }

        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        if ($this->adapter->getSharedTables() && $collection->getTenant() != $this->adapter->getTenant()) {
            throw new NotFoundException('Collection not found');
        }

        return $this->adapter->getSizeOfCollectionOnDisk($collection->getId());
    }

    /**
     * Analyze a collection updating its metadata on the database engine
     *
     * @param string $collection
     * @return bool
     */
    public function analyzeCollection(string $collection): bool
    {
        return $this->adapter->analyzeCollection($collection);
    }

    /**
     * Delete Collection
     *
     * @param string $id
     *
     * @return bool
     * @throws DatabaseException
     */
    public function deleteCollection(string $id): bool
    {
        $collection = $this->silent(fn () => $this->getDocument(self::METADATA, $id));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        if ($this->adapter->getSharedTables() && $collection->getTenant() != $this->adapter->getTenant()) {
            throw new NotFoundException('Collection not found');
        }

        $relationships = \array_filter(
            $collection->getAttribute('attributes'),
            fn ($attribute) => $attribute->getAttribute('type') === Database::VAR_RELATIONSHIP
        );

        foreach ($relationships as $relationship) {
            $this->deleteRelationship($collection->getId(), $relationship->getId());
        }

        // Re-fetch collection to get current state after relationship deletions
        $currentCollection = $this->silent(fn () => $this->getDocument(self::METADATA, $id));
        $currentAttributes = $currentCollection->isEmpty() ? [] : $currentCollection->getAttribute('attributes', []);
        $currentIndexes = $currentCollection->isEmpty() ? [] : $currentCollection->getAttribute('indexes', []);

        $schemaDeleted = false;
        try {
            $this->adapter->deleteCollection($id);
            $schemaDeleted = true;
        } catch (NotFoundException) {
            // Ignore — collection already absent from schema
        }

        if ($id === self::METADATA) {
            $deleted = true;
        } else {
            try {
                $deleted = $this->silent(fn () => $this->deleteDocument(self::METADATA, $id));
            } catch (\Throwable $e) {
                if ($schemaDeleted) {
                    try {
                        $this->adapter->createCollection($id, $currentAttributes, $currentIndexes);
                    } catch (\Throwable) {
                        // Silent rollback — best effort to restore consistency
                    }
                }
                throw new DatabaseException(
                    "Failed to persist metadata for collection deletion '{$id}': " . $e->getMessage(),
                    previous: $e
                );
            }
        }

        if ($deleted) {
            try {
                $this->trigger(self::EVENT_COLLECTION_DELETE, $collection);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        $this->purgeCachedCollection($id);

        return $deleted;
    }

    /**
     * Create Attribute
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param int $size utf8mb4 chars length
     * @param bool $required
     * @param mixed $default
     * @param bool $signed
     * @param bool $array
     * @param string|null $format optional validation format of attribute
     * @param array<string, mixed> $formatOptions assoc array with custom options that can be passed for the format validation
     * @param array<string> $filters
     *
     * @return bool
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws LimitException
     * @throws StructureException
     * @throws Exception
     */
    public function createAttribute(string $collection, string $id, string $type, int $size, bool $required, mixed $default = null, bool $signed = true, bool $array = false, ?string $format = null, array $formatOptions = [], array $filters = []): bool
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        if (in_array($type, self::ATTRIBUTE_FILTER_TYPES)) {
            $filters[] = $type;
            $filters = array_unique($filters);
        }

        $size = $this->normalizeBigIntSize($type, $size);

        $existsInSchema = false;

        $schemaAttributes = $this->adapter->getSupportForSchemaAttributes()
            ? $this->getSchemaAttributes($collection->getId())
            : [];

        try {
            $attribute = $this->validateAttribute(
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
                $schemaAttributes
            );
        } catch (DuplicateException $e) {
            // If the column exists in the physical schema but not in collection
            // metadata, this is recovery from a partial failure where the column
            // was created but metadata wasn't updated. Allow re-creation by
            // skipping physical column creation and proceeding to metadata update.
            // checkDuplicateId (metadata) runs before checkDuplicateInSchema, so
            // if the attribute is absent from metadata the duplicate is in the
            // physical schema only — a recoverable partial-failure state.
            $existsInMetadata = false;
            foreach ($collection->getAttribute('attributes', []) as $attr) {
                if (\strtolower($attr->getAttribute('key', $attr->getId())) === \strtolower($id)) {
                    $existsInMetadata = true;
                    break;
                }
            }

            if ($existsInMetadata) {
                throw $e;
            }

            // Check if the existing schema column matches the requested type.
            // If it matches we can skip column creation. If not, drop the
            // orphaned column so it gets recreated with the correct type.
            $typesMatch = true;
            $expectedColumnType = $this->adapter->getColumnType($type, $size, $signed, $array, $required);
            if ($expectedColumnType !== '') {
                $filteredId = $this->adapter->filter($id);
                foreach ($schemaAttributes as $schemaAttr) {
                    $schemaId = $schemaAttr->getId();
                    if (\strtolower($schemaId) === \strtolower($filteredId)) {
                        $actualColumnType = \strtoupper($schemaAttr->getAttribute('columnType', ''));
                        if ($actualColumnType !== \strtoupper($expectedColumnType)) {
                            $typesMatch = false;
                        }
                        break;
                    }
                }
            }

            if (!$typesMatch) {
                // Column exists with wrong type and is not tracked in metadata,
                // so no indexes or relationships reference it. Drop and recreate.
                $this->adapter->deleteAttribute($collection->getId(), $id);
            } else {
                $existsInSchema = true;
            }

            $attribute = new Document([
                '$id' => ID::custom($id),
                'key' => $id,
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
        }

        $created = false;

        if (!$existsInSchema) {
            try {
                $created = $this->adapter->createAttribute($collection->getId(), $id, $type, $size, $signed, $array, $required);

                if (!$created) {
                    throw new DatabaseException('Failed to create attribute');
                }
            } catch (DuplicateException) {
                // Attribute not in metadata (orphan detection above confirmed this).
                // A DuplicateException from the adapter means the column exists only
                // in physical schema — suppress and proceed to metadata update.
            }
        }

        $collection->setAttribute('attributes', $attribute, Document::SET_TYPE_APPEND);

        $this->updateMetadata(
            collection: $collection,
            rollbackOperation: fn () => $this->cleanupAttribute($collection->getId(), $id),
            shouldRollback: $created,
            operationDescription: "attribute creation '{$id}'"
        );

        $this->withRetries(fn () => $this->purgeCachedCollection($collection->getId()));
        $this->withRetries(fn () => $this->purgeCachedDocumentInternal(self::METADATA, $collection->getId()));

        try {
            $this->trigger(self::EVENT_DOCUMENT_PURGE, new Document([
                '$id' => $collection->getId(),
                '$collection' => self::METADATA
            ]));
        } catch (\Throwable $e) {
            // Ignore
        }

        try {
            $this->trigger(self::EVENT_ATTRIBUTE_CREATE, $attribute);
        } catch (\Throwable $e) {
            // Ignore
        }

        return true;
    }

    /**
     * Create Attribute
     *
     * @param string $collection
     * @param array<array<string, mixed>> $attributes
     * @return bool
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws LimitException
     * @throws StructureException
     * @throws Exception
     */
    public function createAttributes(string $collection, array $attributes): bool
    {
        if (empty($attributes)) {
            throw new DatabaseException('No attributes to create');
        }

        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        $schemaAttributes = $this->adapter->getSupportForSchemaAttributes()
            ? $this->getSchemaAttributes($collection->getId())
            : [];

        $attributeDocuments = [];
        $attributesToCreate = [];
        foreach ($attributes as $attribute) {
            if (!isset($attribute['$id'])) {
                throw new DatabaseException('Missing attribute key');
            }
            if (!isset($attribute['type'])) {
                throw new DatabaseException('Missing attribute type');
            }
            if (!isset($attribute['size'])) {
                throw new DatabaseException('Missing attribute size');
            }
            if (!isset($attribute['required'])) {
                throw new DatabaseException('Missing attribute required');
            }
            if (!isset($attribute['default'])) {
                $attribute['default'] = null;
            }
            if (!isset($attribute['signed'])) {
                $attribute['signed'] = true;
            }
            if (!isset($attribute['array'])) {
                $attribute['array'] = false;
            }
            if (!isset($attribute['format'])) {
                $attribute['format'] = null;
            }
            if (!isset($attribute['formatOptions'])) {
                $attribute['formatOptions'] = [];
            }
            if (!isset($attribute['filters'])) {
                $attribute['filters'] = [];
            }

            $attribute['size'] = $this->normalizeBigIntSize($attribute['type'], $attribute['size']);

            $existsInSchema = false;

            try {
                $attributeDocument = $this->validateAttribute(
                    $collection,
                    $attribute['$id'],
                    $attribute['type'],
                    $attribute['size'],
                    $attribute['required'],
                    $attribute['default'],
                    $attribute['signed'],
                    $attribute['array'],
                    $attribute['format'],
                    $attribute['formatOptions'],
                    $attribute['filters'],
                    $schemaAttributes
                );
            } catch (DuplicateException $e) {
                // Check if the duplicate is in metadata or only in schema
                $existsInMetadata = false;
                foreach ($collection->getAttribute('attributes', []) as $attr) {
                    if (\strtolower($attr->getAttribute('key', $attr->getId())) === \strtolower($attribute['$id'])) {
                        $existsInMetadata = true;
                        break;
                    }
                }

                if ($existsInMetadata) {
                    throw $e;
                }

                // Schema-only orphan — check type match
                $expectedColumnType = $this->adapter->getColumnType(
                    $attribute['type'],
                    $attribute['size'],
                    $attribute['signed'],
                    $attribute['array'],
                    $attribute['required']
                );
                if ($expectedColumnType !== '') {
                    $filteredId = $this->adapter->filter($attribute['$id']);
                    foreach ($schemaAttributes as $schemaAttr) {
                        if (\strtolower($schemaAttr->getId()) === \strtolower($filteredId)) {
                            $actualColumnType = \strtoupper($schemaAttr->getAttribute('columnType', ''));
                            if ($actualColumnType !== \strtoupper($expectedColumnType)) {
                                // Type mismatch — drop orphaned column so it gets recreated
                                $this->adapter->deleteAttribute($collection->getId(), $attribute['$id']);
                            } else {
                                $existsInSchema = true;
                            }
                            break;
                        }
                    }
                }

                $attributeDocument = new Document([
                    '$id' => ID::custom($attribute['$id']),
                    'key' => $attribute['$id'],
                    'type' => $attribute['type'],
                    'size' => $attribute['size'],
                    'required' => $attribute['required'],
                    'default' => $attribute['default'],
                    'signed' => $attribute['signed'],
                    'array' => $attribute['array'],
                    'format' => $attribute['format'],
                    'formatOptions' => $attribute['formatOptions'],
                    'filters' => $attribute['filters'],
                ]);
            }

            $attributeDocuments[] = $attributeDocument;
            if (!$existsInSchema) {
                $attributesToCreate[] = $attribute;
            }
        }

        $created = false;

        if (!empty($attributesToCreate)) {
            try {
                $created = $this->adapter->createAttributes($collection->getId(), $attributesToCreate);

                if (!$created) {
                    throw new DatabaseException('Failed to create attributes');
                }
            } catch (DuplicateException) {
                // Batch failed because at least one column already exists.
                // Fallback to per-attribute creation so non-duplicates still land in schema.
                foreach ($attributesToCreate as $attr) {
                    try {
                        $this->adapter->createAttribute(
                            $collection->getId(),
                            $attr['$id'],
                            $attr['type'],
                            $attr['size'],
                            $attr['signed'],
                            $attr['array'],
                            $attr['required']
                        );
                        $created = true;
                    } catch (DuplicateException) {
                        // Column already exists in schema — skip
                    }
                }
            }
        }

        foreach ($attributeDocuments as $attributeDocument) {
            $collection->setAttribute('attributes', $attributeDocument, Document::SET_TYPE_APPEND);
        }

        $this->updateMetadata(
            collection: $collection,
            rollbackOperation: fn () => $this->cleanupAttributes($collection->getId(), $attributeDocuments),
            shouldRollback: $created,
            operationDescription: 'attributes creation',
            rollbackReturnsErrors: true
        );

        $this->withRetries(fn () => $this->purgeCachedCollection($collection->getId()));
        $this->withRetries(fn () => $this->purgeCachedDocumentInternal(self::METADATA, $collection->getId()));

        try {
            $this->trigger(self::EVENT_DOCUMENT_PURGE, new Document([
                '$id' => $collection->getId(),
                '$collection' => self::METADATA
            ]));
        } catch (\Throwable $e) {
            // Ignore
        }

        try {
            $this->trigger(self::EVENT_ATTRIBUTE_CREATE, $attributeDocuments);
        } catch (\Throwable $e) {
            // Ignore
        }

        return true;
    }

    /**
     * Normalize BIGINT size metadata.
     */
    private function normalizeBigIntSize(string $type, int $size): int
    {
        return $type === self::VAR_BIGINT ? 0 : $size;
    }

    /**
     * @param Document $collection
     * @param string $id
     * @param string $type
     * @param int $size
     * @param bool $required
     * @param mixed $default
     * @param bool $signed
     * @param bool $array
     * @param string $format
     * @param array<string, mixed> $formatOptions
     * @param array<string> $filters
     * @param array<Document>|null $schemaAttributes Pre-fetched schema attributes, or null to fetch internally
     * @return Document
     * @throws DuplicateException
     * @throws LimitException
     * @throws Exception
     */
    private function validateAttribute(
        Document $collection,
        string $id,
        string $type,
        int $size,
        bool $required,
        mixed $default,
        bool $signed,
        bool $array,
        ?string $format,
        array $formatOptions,
        array $filters,
        ?array $schemaAttributes = null
    ): Document {
        $size = $this->normalizeBigIntSize($type, $size);

        $attribute = new Document([
            '$id' => ID::custom($id),
            'key' => $id,
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

        $collectionClone = clone $collection;
        $collectionClone->setAttribute('attributes', $attribute, Document::SET_TYPE_APPEND);

        $validator = new AttributeValidator(
            attributes: $collection->getAttribute('attributes', []),
            schemaAttributes: $schemaAttributes ?? ($this->adapter->getSupportForSchemaAttributes()
                ? $this->getSchemaAttributes($collection->getId())
                : []),
            maxAttributes: $this->adapter->getLimitForAttributes(),
            maxWidth: $this->adapter->getDocumentSizeLimit(),
            maxStringLength: $this->adapter->getLimitForString(),
            maxVarcharLength: $this->adapter->getMaxVarcharLength(),
            maxIntLength: $this->adapter->getLimitForInt(),
            maxBigIntLength: $this->adapter->getLimitForBigInt(),
            supportForSchemaAttributes: $this->adapter->getSupportForSchemaAttributes(),
            supportForVectors: $this->adapter->getSupportForVectors(),
            supportForSpatialAttributes: $this->adapter->getSupportForSpatialAttributes(),
            supportForObject: $this->adapter->getSupportForObject(),
            supportUnsignedBigInt: $this->adapter->getSupportForUnsignedBigInt(),
            attributeCountCallback: fn () => $this->adapter->getCountOfAttributes($collectionClone),
            attributeWidthCallback: fn () => $this->adapter->getAttributeWidth($collectionClone),
            filterCallback: fn ($id) => $this->adapter->filter($id),
            isMigrating: $this->isMigrating(),
            sharedTables: $this->getSharedTables(),
        );

        $validator->isValid($attribute);

        return $attribute;
    }

    /**
     * Get the list of required filters for each data type
     *
     * @param string|null $type Type of the attribute
     *
     * @return array<string>
     */
    protected function getRequiredFilters(?string $type): array
    {
        return match ($type) {
            self::VAR_DATETIME => ['datetime'],
            default => [],
        };
    }

    /**
     * Function to validate if the default value of an attribute matches its attribute type
     *
     * @param string $type Type of the attribute
     * @param mixed $default Default value of the attribute
     *
     * @return void
     * @throws DatabaseException
     */
    protected function validateDefaultTypes(string $type, mixed $default): void
    {
        $defaultType = \gettype($default);

        if ($defaultType === 'NULL') {
            // Disable null. No validation required
            return;
        }

        if ($defaultType === 'array') {
            // Spatial types require the array itself
            if (!in_array($type, Database::SPATIAL_TYPES) && $type != Database::VAR_OBJECT) {
                foreach ($default as $value) {
                    $this->validateDefaultTypes($type, $value);
                }
            }
            return;
        }

        switch ($type) {
            case self::VAR_STRING:
            case self::VAR_VARCHAR:
            case self::VAR_TEXT:
            case self::VAR_MEDIUMTEXT:
            case self::VAR_LONGTEXT:
                if ($defaultType !== 'string') {
                    throw new DatabaseException('Default value ' . $default . ' does not match given type ' . $type);
                }
                break;
            case self::VAR_INTEGER:
            case self::VAR_FLOAT:
            case self::VAR_BOOLEAN:
                if ($type !== $defaultType) {
                    throw new DatabaseException('Default value ' . $default . ' does not match given type ' . $type);
                }
                break;
            case Database::VAR_BIGINT:
                if ($defaultType !== 'integer' && $defaultType !== 'string') {
                    throw new DatabaseException('Default value ' . $default . ' does not match given type ' . $type);
                }
                if ($defaultType === 'string' && !BigIntValidator::isIntegerString($default)) {
                    throw new DatabaseException('Default value ' . $default . ' is not a valid integer string for type bigint');
                }
                break;
            case self::VAR_DATETIME:
                if ($defaultType !== self::VAR_STRING) {
                    throw new DatabaseException('Default value ' . $default . ' does not match given type ' . $type);
                }
                break;
            case self::VAR_VECTOR:
                // When validating individual vector components (from recursion), they should be numeric
                if ($defaultType !== 'double' && $defaultType !== 'integer') {
                    throw new DatabaseException('Vector components must be numeric values (float or integer)');
                }
                break;
            default:
                $supportedTypes = [
                    self::VAR_STRING,
                    self::VAR_VARCHAR,
                    self::VAR_TEXT,
                    self::VAR_MEDIUMTEXT,
                    self::VAR_LONGTEXT,
                    self::VAR_INTEGER,
                    self::VAR_BIGINT,
                    self::VAR_FLOAT,
                    self::VAR_BOOLEAN,
                    self::VAR_DATETIME,
                    self::VAR_RELATIONSHIP
                ];
                if ($this->adapter->getSupportForVectors()) {
                    $supportedTypes[] = self::VAR_VECTOR;
                }
                if ($this->adapter->getSupportForSpatialAttributes()) {
                    \array_push($supportedTypes, ...self::SPATIAL_TYPES);
                }
                throw new DatabaseException('Unknown attribute type: ' . $type . '. Must be one of ' . implode(', ', $supportedTypes));
        }
    }

    /**
     * Update attribute metadata. Utility method for update attribute methods.
     *
     * @param string $collection
     * @param string $id
     * @param callable $updateCallback method that receives document, and returns it with changes applied
     *
     * @return Document
     * @throws ConflictException
     * @throws DatabaseException
     */
    protected function updateIndexMeta(string $collection, string $id, callable $updateCallback): Document
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->getId() === self::METADATA) {
            throw new DatabaseException('Cannot update metadata indexes');
        }

        $indexes = $collection->getAttribute('indexes', []);
        $index = \array_search($id, \array_map(fn ($index) => $index['$id'], $indexes));

        if ($index === false) {
            throw new NotFoundException('Index not found');
        }

        // Execute update from callback
        $updateCallback($indexes[$index], $collection, $index);

        $collection->setAttribute('indexes', $indexes);

        $this->updateMetadata(
            collection: $collection,
            rollbackOperation: null,
            shouldRollback: false,
            operationDescription: "index metadata update '{$id}'"
        );

        return $indexes[$index];
    }

    /**
     * Update attribute metadata. Utility method for update attribute methods.
     *
     * @param string $collection
     * @param string $id
     * @param callable(Document, Document, int|string): void $updateCallback method that receives document, and returns it with changes applied
     *
     * @return Document
     * @throws ConflictException
     * @throws DatabaseException
     */
    protected function updateAttributeMeta(string $collection, string $id, callable $updateCallback): Document
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->getId() === self::METADATA) {
            throw new DatabaseException('Cannot update metadata attributes');
        }

        $attributes = $collection->getAttribute('attributes', []);
        $index = \array_search($id, \array_map(fn ($attribute) => $attribute['$id'], $attributes));

        if ($index === false) {
            throw new NotFoundException('Attribute not found');
        }

        // Execute update from callback
        $updateCallback($attributes[$index], $collection, $index);

        $collection->setAttribute('attributes', $attributes);

        $this->updateMetadata(
            collection: $collection,
            rollbackOperation: null,
            shouldRollback: false,
            operationDescription: "attribute metadata update '{$id}'"
        );

        try {
            $this->trigger(self::EVENT_ATTRIBUTE_UPDATE, $attributes[$index]);
        } catch (\Throwable $e) {
            // Ignore
        }

        return $attributes[$index];
    }

    /**
     * Update required status of attribute.
     *
     * @param string $collection
     * @param string $id
     * @param bool $required
     *
     * @return Document
     * @throws Exception
     */
    public function updateAttributeRequired(string $collection, string $id, bool $required): Document
    {
        return $this->updateAttributeMeta($collection, $id, function ($attribute) use ($required) {
            $attribute->setAttribute('required', $required);
        });
    }

    /**
     * Update format of attribute.
     *
     * @param string $collection
     * @param string $id
     * @param string $format validation format of attribute
     *
     * @return Document
     * @throws Exception
     */
    public function updateAttributeFormat(string $collection, string $id, string $format): Document
    {
        return $this->updateAttributeMeta($collection, $id, function ($attribute) use ($format) {
            if (!Structure::hasFormat($format, $attribute->getAttribute('type'))) {
                throw new DatabaseException('Format "' . $format . '" not available for attribute type "' . $attribute->getAttribute('type') . '"');
            }

            $attribute->setAttribute('format', $format);
        });
    }

    /**
     * Update format options of attribute.
     *
     * @param string $collection
     * @param string $id
     * @param array<string, mixed> $formatOptions assoc array with custom options that can be passed for the format validation
     *
     * @return Document
     * @throws Exception
     */
    public function updateAttributeFormatOptions(string $collection, string $id, array $formatOptions): Document
    {
        return $this->updateAttributeMeta($collection, $id, function ($attribute) use ($formatOptions) {
            $attribute->setAttribute('formatOptions', $formatOptions);
        });
    }

    /**
     * Update filters of attribute.
     *
     * @param string $collection
     * @param string $id
     * @param array<string> $filters
     *
     * @return Document
     * @throws Exception
     */
    public function updateAttributeFilters(string $collection, string $id, array $filters): Document
    {
        return $this->updateAttributeMeta($collection, $id, function ($attribute) use ($filters) {
            $attribute->setAttribute('filters', $filters);
        });
    }

    /**
     * Update default value of attribute
     *
     * @param string $collection
     * @param string $id
     * @param mixed $default
     *
     * @return Document
     * @throws Exception
     */
    public function updateAttributeDefault(string $collection, string $id, mixed $default = null): Document
    {
        return $this->updateAttributeMeta($collection, $id, function ($attribute) use ($default) {
            if ($attribute->getAttribute('required') === true) {
                throw new DatabaseException('Cannot set a default value on a required attribute');
            }

            $this->validateDefaultTypes($attribute->getAttribute('type'), $default);

            $attribute->setAttribute('default', $default);
        });
    }

    /**
     * Update Attribute. This method is for updating data that causes underlying structure to change. Check out other updateAttribute methods if you are looking for metadata adjustments.
     *
     * @param string $collection
     * @param string $id
     * @param string|null $type
     * @param int|null $size utf8mb4 chars length
     * @param bool|null $required
     * @param mixed $default
     * @param bool $signed
     * @param bool $array
     * @param string|null $format
     * @param array<string, mixed>|null $formatOptions
     * @param array<string>|null $filters
     * @param string|null $newKey
     * @return Document
     * @throws Exception
     */
    public function updateAttribute(string $collection, string $id, ?string $type = null, ?int $size = null, ?bool $required = null, mixed $default = null, ?bool $signed = null, ?bool $array = null, ?string $format = null, ?array $formatOptions = null, ?array $filters = null, ?string $newKey = null): Document
    {
        $collectionDoc = $this->silent(fn () => $this->getCollection($collection));

        if ($collectionDoc->getId() === self::METADATA) {
            throw new DatabaseException('Cannot update metadata attributes');
        }

        $attributes = $collectionDoc->getAttribute('attributes', []);
        $attributeIndex = \array_search($id, \array_map(fn ($attribute) => $attribute['$id'], $attributes));

        if ($attributeIndex === false) {
            throw new NotFoundException('Attribute not found');
        }

        $attribute = $attributes[$attributeIndex];

        $originalType = $attribute->getAttribute('type');
        $originalSize = $attribute->getAttribute('size');
        $originalSigned = $attribute->getAttribute('signed');
        $originalArray = $attribute->getAttribute('array');
        $originalRequired = $attribute->getAttribute('required');
        $originalKey = $attribute->getAttribute('key');

        $originalIndexes = [];
        foreach ($collectionDoc->getAttribute('indexes', []) as $index) {
            $originalIndexes[] = clone $index;
        }

        $altering = !\is_null($type)
            || !\is_null($size)
            || !\is_null($signed)
            || !\is_null($array)
            || !\is_null($newKey);
        $type ??= $attribute->getAttribute('type');
        $size ??= $attribute->getAttribute('size');
        $signed ??= $attribute->getAttribute('signed');
        $required ??= $attribute->getAttribute('required');
        $default ??= $attribute->getAttribute('default');
        $array ??= $attribute->getAttribute('array');
        $format ??= $attribute->getAttribute('format');
        $formatOptions ??= $attribute->getAttribute('formatOptions');
        $filters ??= $attribute->getAttribute('filters');

        $size = $this->normalizeBigIntSize($type, $size);

        if ($required === true && !\is_null($default)) {
            $default = null;
        }

        // we need to alter table attribute type to NOT NULL/NULL for change in required
        if (!$this->adapter->getSupportForSpatialIndexNull() && in_array($type, Database::SPATIAL_TYPES)) {
            $altering = true;
        }

        switch ($type) {
            case self::VAR_STRING:
                if (empty($size)) {
                    throw new DatabaseException('Size length is required');
                }

                if ($size > $this->adapter->getLimitForString()) {
                    throw new DatabaseException('Max size allowed for string is: ' . number_format($this->adapter->getLimitForString()));
                }
                break;

            case self::VAR_VARCHAR:
                if (empty($size)) {
                    throw new DatabaseException('Size length is required');
                }

                if ($size > $this->adapter->getMaxVarcharLength()) {
                    throw new DatabaseException('Max size allowed for varchar is: ' . number_format($this->adapter->getMaxVarcharLength()));
                }
                break;

            case self::VAR_TEXT:
            case self::VAR_MEDIUMTEXT:
            case self::VAR_LONGTEXT:
                // Text types don't require size validation as they have fixed max sizes
                break;

            case self::VAR_INTEGER:
                $limit = ($signed) ? $this->adapter->getLimitForInt() / 2 : $this->adapter->getLimitForInt();
                if ($size > $limit) {
                    throw new DatabaseException('Max size allowed for int is: ' . number_format($limit));
                }
                break;
            case self::VAR_BIGINT:
                break;
            case self::VAR_FLOAT:
            case self::VAR_BOOLEAN:
            case self::VAR_DATETIME:
                if (!empty($size)) {
                    throw new DatabaseException('Size must be empty');
                }
                break;
            case self::VAR_OBJECT:
                if (!$this->adapter->getSupportForObject()) {
                    throw new DatabaseException('Object attributes are not supported');
                }
                if (!empty($size)) {
                    throw new DatabaseException('Size must be empty for object attributes');
                }
                if (!empty($array)) {
                    throw new DatabaseException('Object attributes cannot be arrays');
                }
                break;
            case self::VAR_POINT:
            case self::VAR_LINESTRING:
            case self::VAR_POLYGON:
                if (!$this->adapter->getSupportForSpatialAttributes()) {
                    throw new DatabaseException('Spatial attributes are not supported');
                }
                if (!empty($size)) {
                    throw new DatabaseException('Size must be empty for spatial attributes');
                }
                if (!empty($array)) {
                    throw new DatabaseException('Spatial attributes cannot be arrays');
                }
                break;
            case self::VAR_VECTOR:
                if (!$this->adapter->getSupportForVectors()) {
                    throw new DatabaseException('Vector types are not supported by the current database');
                }
                if ($array) {
                    throw new DatabaseException('Vector type cannot be an array');
                }
                if ($size <= 0) {
                    throw new DatabaseException('Vector dimensions must be a positive integer');
                }
                if ($size > self::MAX_VECTOR_DIMENSIONS) {
                    throw new DatabaseException('Vector dimensions cannot exceed ' . self::MAX_VECTOR_DIMENSIONS);
                }
                if ($default !== null) {
                    if (!\is_array($default)) {
                        throw new DatabaseException('Vector default value must be an array');
                    }
                    if (\count($default) !== $size) {
                        throw new DatabaseException('Vector default value must have exactly ' . $size . ' elements');
                    }
                    foreach ($default as $component) {
                        if (!\is_int($component) && !\is_float($component)) {
                            throw new DatabaseException('Vector default value must contain only numeric elements');
                        }
                    }
                }
                break;
            default:
                $supportedTypes = [
                    self::VAR_STRING,
                    self::VAR_VARCHAR,
                    self::VAR_TEXT,
                    self::VAR_MEDIUMTEXT,
                    self::VAR_LONGTEXT,
                    self::VAR_INTEGER,
                    self::VAR_BIGINT,
                    self::VAR_FLOAT,
                    self::VAR_BOOLEAN,
                    self::VAR_DATETIME,
                    self::VAR_RELATIONSHIP
                ];
                if ($this->adapter->getSupportForVectors()) {
                    $supportedTypes[] = self::VAR_VECTOR;
                }
                if ($this->adapter->getSupportForSpatialAttributes()) {
                    \array_push($supportedTypes, ...self::SPATIAL_TYPES);
                }
                throw new DatabaseException('Unknown attribute type: ' . $type . '. Must be one of ' . implode(', ', $supportedTypes));
        }

        /** Ensure required filters for the attribute are passed */
        $requiredFilters = $this->getRequiredFilters($type);
        if (!empty(array_diff($requiredFilters, $filters))) {
            throw new DatabaseException("Attribute of type: $type requires the following filters: " . implode(",", $requiredFilters));
        }

        if ($format) {
            if (!Structure::hasFormat($format, $type)) {
                throw new DatabaseException('Format ("' . $format . '") not available for this attribute type ("' . $type . '")');
            }
        }

        if (!\is_null($default)) {
            if ($required) {
                throw new DatabaseException('Cannot set a default value on a required attribute');
            }

            $this->validateDefaultTypes($type, $default);
        }

        $attribute
            ->setAttribute('$id', $newKey ?? $id)
            ->setattribute('key', $newKey ?? $id)
            ->setAttribute('type', $type)
            ->setAttribute('size', $size)
            ->setAttribute('signed', $signed)
            ->setAttribute('array', $array)
            ->setAttribute('format', $format)
            ->setAttribute('formatOptions', $formatOptions)
            ->setAttribute('filters', $filters)
            ->setAttribute('required', $required)
            ->setAttribute('default', $default);

        $attributes = $collectionDoc->getAttribute('attributes');
        $attributes[$attributeIndex] = $attribute;
        $collectionDoc->setAttribute('attributes', $attributes, Document::SET_TYPE_ASSIGN);

        if (
            $this->adapter->getDocumentSizeLimit() > 0 &&
            $this->adapter->getAttributeWidth($collectionDoc) >= $this->adapter->getDocumentSizeLimit()
        ) {
            throw new LimitException('Row width limit reached. Cannot update attribute.');
        }

        if (in_array($type, self::SPATIAL_TYPES, true) && !$this->adapter->getSupportForSpatialIndexNull()) {
            $attributeMap = [];
            foreach ($attributes as $attrDoc) {
                $key = \strtolower($attrDoc->getAttribute('key', $attrDoc->getAttribute('$id')));
                $attributeMap[$key] = $attrDoc;
            }

            $indexes = $collectionDoc->getAttribute('indexes', []);
            foreach ($indexes as $index) {
                if ($index->getAttribute('type') !== self::INDEX_SPATIAL) {
                    continue;
                }
                $indexAttributes = $index->getAttribute('attributes', []);
                foreach ($indexAttributes as $attributeName) {
                    $lookup = \strtolower($attributeName);
                    if (!isset($attributeMap[$lookup])) {
                        continue;
                    }
                    $attrDoc = $attributeMap[$lookup];
                    $attrType = $attrDoc->getAttribute('type');
                    $attrRequired = (bool)$attrDoc->getAttribute('required', false);

                    if (in_array($attrType, self::SPATIAL_TYPES, true) && !$attrRequired) {
                        throw new IndexException('Spatial indexes do not allow null values. Mark the attribute "' . $attributeName . '" as required or create the index on a column with no null values.');
                    }
                }
            }
        }

        $updated = false;

        if ($altering) {
            $indexes = $collectionDoc->getAttribute('indexes');

            if (!\is_null($newKey) && $id !== $newKey) {
                foreach ($indexes as $index) {
                    if (in_array($id, $index['attributes'])) {
                        $index['attributes'] = array_map(function ($attribute) use ($id, $newKey) {
                            return $attribute === $id ? $newKey : $attribute;
                        }, $index['attributes']);
                    }
                }

                /**
                 * Check index dependency if we are changing the key
                 */
                $validator = new IndexDependencyValidator(
                    $collectionDoc->getAttribute('indexes', []),
                    $this->adapter->getSupportForCastIndexArray(),
                );

                if (!$validator->isValid($attribute)) {
                    throw new DependencyException($validator->getDescription());
                }
            }

            /**
             * Since we allow changing type & size we need to validate index length
             */
            if ($this->validate) {
                $validator = new IndexValidator(
                    $attributes,
                    $originalIndexes,
                    $this->adapter->getMaxIndexLength(),
                    $this->adapter->getInternalIndexesKeys(),
                    $this->adapter->getSupportForIndexArray(),
                    $this->adapter->getSupportForSpatialIndexNull(),
                    $this->adapter->getSupportForSpatialIndexOrder(),
                    $this->adapter->getSupportForVectors(),
                    $this->adapter->getSupportForAttributes(),
                    $this->adapter->getSupportForMultipleFulltextIndexes(),
                    $this->adapter->getSupportForIdenticalIndexes(),
                    $this->adapter->getSupportForObjectIndexes(),
                    $this->adapter->getSupportForTrigramIndex(),
                    $this->adapter->getSupportForSpatialAttributes(),
                    $this->adapter->getSupportForIndex(),
                    $this->adapter->getSupportForUniqueIndex(),
                    $this->adapter->getSupportForFulltextIndex(),
                    $this->adapter->getSupportForTTLIndexes(),
                    $this->adapter->getSupportForObject()
                );

                foreach ($indexes as $index) {
                    if (!$validator->isValid($index)) {
                        throw new IndexException($validator->getDescription());
                    }
                }
            }

            $updated = $this->adapter->updateAttribute($collection, $id, $type, $size, $signed, $array, $newKey, $required);

            if (!$updated) {
                throw new DatabaseException('Failed to update attribute');
            }
        }

        $collectionDoc->setAttribute('attributes', $attributes);

        $this->updateMetadata(
            collection: $collectionDoc,
            rollbackOperation: fn () => $this->adapter->updateAttribute(
                $collection,
                $newKey ?? $id,
                $originalType,
                (int)$originalSize,
                $originalSigned,
                $originalArray,
                $originalKey,
                $originalRequired
            ),
            shouldRollback: $updated,
            operationDescription: "attribute update '{$id}'",
            silentRollback: true
        );

        if ($altering) {
            $this->withRetries(fn () => $this->purgeCachedCollection($collection));
        }
        $this->withRetries(fn () => $this->purgeCachedDocumentInternal(self::METADATA, $collection));

        try {
            $this->trigger(self::EVENT_DOCUMENT_PURGE, new Document([
                '$id' => $collection,
                '$collection' => self::METADATA
            ]));
        } catch (\Throwable $e) {
            // Ignore
        }

        try {
            $this->trigger(self::EVENT_ATTRIBUTE_UPDATE, $attribute);
        } catch (\Throwable $e) {
            // Ignore
        }

        return $attribute;
    }

    /**
     * Checks if attribute can be added to collection.
     * Used to check attribute limits without asking the database
     * Returns true if attribute can be added to collection, throws exception otherwise
     *
     * @param Document $collection
     * @param Document $attribute
     *
     * @return bool
     * @throws LimitException
     */
    public function checkAttribute(Document $collection, Document $attribute): bool
    {
        $collection = clone $collection;

        $collection->setAttribute('attributes', $attribute, Document::SET_TYPE_APPEND);

        if (
            $this->adapter->getLimitForAttributes() > 0 &&
            $this->adapter->getCountOfAttributes($collection) > $this->adapter->getLimitForAttributes()
        ) {
            throw new LimitException('Column limit reached. Cannot create new attribute. Current attribute count is ' . $this->adapter->getCountOfAttributes($collection) . ' but the maximum is ' . $this->adapter->getLimitForAttributes() . '. Remove some attributes to free up space.');
        }

        if (
            $this->adapter->getDocumentSizeLimit() > 0 &&
            $this->adapter->getAttributeWidth($collection) >= $this->adapter->getDocumentSizeLimit()
        ) {
            throw new LimitException('Row width limit reached. Cannot create new attribute. Current row width is ' . $this->adapter->getAttributeWidth($collection) . ' bytes but the maximum is ' . $this->adapter->getDocumentSizeLimit() . ' bytes. Reduce the size of existing attributes or remove some attributes to free up space.');
        }

        return true;
    }

    /**
     * Delete Attribute
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     * @throws ConflictException
     * @throws DatabaseException
     */
    public function deleteAttribute(string $collection, string $id): bool
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));
        $attributes = $collection->getAttribute('attributes', []);
        $indexes = $collection->getAttribute('indexes', []);

        $attribute = null;

        foreach ($attributes as $key => $value) {
            if (isset($value['$id']) && $value['$id'] === $id) {
                $attribute = $value;
                unset($attributes[$key]);
                break;
            }
        }

        if (\is_null($attribute)) {
            throw new NotFoundException('Attribute not found');
        }

        if ($attribute['type'] === self::VAR_RELATIONSHIP) {
            throw new DatabaseException('Cannot delete relationship as an attribute');
        }

        if ($this->validate) {
            $validator = new IndexDependencyValidator(
                $collection->getAttribute('indexes', []),
                $this->adapter->getSupportForCastIndexArray(),
            );

            if (!$validator->isValid($attribute)) {
                throw new DependencyException($validator->getDescription());
            }
        }

        foreach ($indexes as $indexKey => $index) {
            $indexAttributes = $index->getAttribute('attributes', []);

            $indexAttributes = \array_filter($indexAttributes, fn ($attribute) => $attribute !== $id);

            if (empty($indexAttributes)) {
                unset($indexes[$indexKey]);
            } else {
                $index->setAttribute('attributes', \array_values($indexAttributes));
            }
        }

        $collection->setAttribute('attributes', \array_values($attributes));
        $collection->setAttribute('indexes', \array_values($indexes));

        $shouldRollback = false;
        try {
            if (!$this->adapter->deleteAttribute($collection->getId(), $id)) {
                throw new DatabaseException('Failed to delete attribute');
            }
            $shouldRollback = true;
        } catch (NotFoundException) {
            // Ignore
        }

        $this->updateMetadata(
            collection: $collection,
            rollbackOperation: fn () => $this->adapter->createAttribute(
                $collection->getId(),
                $id,
                $attribute['type'],
                $attribute['size'],
                $attribute['signed'] ?? true,
                $attribute['array'] ?? false,
                $attribute['required'] ?? false
            ),
            shouldRollback: $shouldRollback,
            operationDescription: "attribute deletion '{$id}'",
            silentRollback: true
        );

        $this->withRetries(fn () => $this->purgeCachedCollection($collection->getId()));
        $this->withRetries(fn () => $this->purgeCachedDocumentInternal(self::METADATA, $collection->getId()));

        try {
            $this->trigger(self::EVENT_DOCUMENT_PURGE, new Document([
                '$id' => $collection->getId(),
                '$collection' => self::METADATA
            ]));
        } catch (\Throwable $e) {
            // Ignore
        }

        try {
            $this->trigger(self::EVENT_ATTRIBUTE_DELETE, $attribute);
        } catch (\Throwable $e) {
            // Ignore
        }

        return true;
    }

    /**
     * Rename Attribute
     *
     * @param string $collection
     * @param string $old Current attribute ID
     * @param string $new
     * @return bool
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws StructureException
     */
    public function renameAttribute(string $collection, string $old, string $new): bool
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        /**
         * @var array<Document> $attributes
         */
        $attributes = $collection->getAttribute('attributes', []);

        /**
         * @var array<Document> $indexes
         */
        $indexes = $collection->getAttribute('indexes', []);

        $attribute = new Document();

        foreach ($attributes as $value) {
            if ($value->getId() === $old) {
                $attribute = $value;
            }

            if ($value->getId() === $new) {
                throw new DuplicateException('Attribute name already used');
            }
        }

        if ($attribute->isEmpty()) {
            throw new NotFoundException('Attribute not found');
        }

        if ($this->validate) {
            $validator = new IndexDependencyValidator(
                $collection->getAttribute('indexes', []),
                $this->adapter->getSupportForCastIndexArray(),
            );

            if (!$validator->isValid($attribute)) {
                throw new DependencyException($validator->getDescription());
            }
        }

        $attribute->setAttribute('$id', $new);
        $attribute->setAttribute('key', $new);

        foreach ($indexes as $index) {
            $indexAttributes = $index->getAttribute('attributes', []);

            $indexAttributes = \array_map(fn ($attr) => ($attr === $old) ? $new : $attr, $indexAttributes);

            $index->setAttribute('attributes', $indexAttributes);
        }

        $renamed = false;
        try {
            $renamed = $this->adapter->renameAttribute($collection->getId(), $old, $new);
            if (!$renamed) {
                throw new DatabaseException('Failed to rename attribute');
            }
        } catch (\Throwable $e) {
            // Check if the rename already happened in schema (orphan from prior
            // partial failure where rename succeeded but metadata update failed).
            // We verified $new doesn't exist in metadata (above), so if $new
            // exists in schema, it must be from a prior rename.
            if ($this->adapter->getSupportForSchemaAttributes()) {
                $schemaAttributes = $this->getSchemaAttributes($collection->getId());
                $filteredNew = $this->adapter->filter($new);
                $newExistsInSchema = false;
                foreach ($schemaAttributes as $schemaAttr) {
                    if (\strtolower($schemaAttr->getId()) === \strtolower($filteredNew)) {
                        $newExistsInSchema = true;
                        break;
                    }
                }
                if ($newExistsInSchema) {
                    $renamed = true;
                } else {
                    throw new DatabaseException("Failed to rename attribute '{$old}' to '{$new}': " . $e->getMessage(), previous: $e);
                }
            } else {
                throw new DatabaseException("Failed to rename attribute '{$old}' to '{$new}': " . $e->getMessage(), previous: $e);
            }
        }

        $collection->setAttribute('attributes', $attributes);
        $collection->setAttribute('indexes', $indexes);

        $this->updateMetadata(
            collection: $collection,
            rollbackOperation: fn () => $this->adapter->renameAttribute($collection->getId(), $new, $old),
            shouldRollback: $renamed,
            operationDescription: "attribute rename '{$old}' to '{$new}'"
        );

        $this->withRetries(fn () => $this->purgeCachedCollection($collection->getId()));

        try {
            $this->trigger(self::EVENT_ATTRIBUTE_UPDATE, $attribute);
        } catch (\Throwable $e) {
            // Ignore
        }

        return $renamed;
    }

    /**
     * Cleanup (delete) a single attribute with retry logic
     *
     * @param string $collectionId The collection ID
     * @param string $attributeId The attribute ID
     * @param int $maxAttempts Maximum retry attempts
     * @return void
     * @throws DatabaseException If cleanup fails after all retries
     */
    private function cleanupAttribute(
        string $collectionId,
        string $attributeId,
        int $maxAttempts = 3
    ): void {
        $this->cleanup(
            fn () => $this->adapter->deleteAttribute($collectionId, $attributeId),
            'attribute',
            $attributeId,
            $maxAttempts
        );
    }

    /**
     * Cleanup (delete) multiple attributes with retry logic
     *
     * @param string $collectionId The collection ID
     * @param array<Document> $attributeDocuments The attribute documents to cleanup
     * @param int $maxAttempts Maximum retry attempts per attribute
     * @return array<string> Array of error messages for failed cleanups (empty if all succeeded)
     */
    private function cleanupAttributes(
        string $collectionId,
        array $attributeDocuments,
        int $maxAttempts = 3
    ): array {
        $errors = [];

        foreach ($attributeDocuments as $attributeDocument) {
            try {
                $this->cleanupAttribute($collectionId, $attributeDocument->getId(), $maxAttempts);
            } catch (DatabaseException $e) {
                // Continue cleaning up other attributes even if one fails
                $errors[] = $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * Cleanup (delete) a collection with retry logic
     *
     * @param string $collectionId The collection ID
     * @param int $maxAttempts Maximum retry attempts
     * @return void
     * @throws DatabaseException If cleanup fails after all retries
     */
    private function cleanupCollection(
        string $collectionId,
        int $maxAttempts = 3
    ): void {
        $this->cleanup(
            fn () => $this->adapter->deleteCollection($collectionId),
            'collection',
            $collectionId,
            $maxAttempts
        );
    }

    /**
     * Cleanup (delete) a relationship with retry logic
     *
     * @param string $collectionId The collection ID
     * @param string $relatedCollectionId The related collection ID
     * @param string $type The relationship type
     * @param bool $twoWay Whether the relationship is two-way
     * @param string $key The relationship key
     * @param string $twoWayKey The two-way relationship key
     * @param string $side The relationship side
     * @param int $maxAttempts Maximum retry attempts
     * @return void
     * @throws DatabaseException If cleanup fails after all retries
     */
    private function cleanupRelationship(
        string $collectionId,
        string $relatedCollectionId,
        string $type,
        bool $twoWay,
        string $key,
        string $twoWayKey,
        string $side = Database::RELATION_SIDE_PARENT,
        int $maxAttempts = 3
    ): void {
        $this->cleanup(
            fn () => $this->adapter->deleteRelationship(
                $collectionId,
                $relatedCollectionId,
                $type,
                $twoWay,
                $key,
                $twoWayKey,
                $side
            ),
            'relationship',
            $key,
            $maxAttempts
        );
    }

    /**
     * Create a relationship attribute
     *
     * @param string $collection
     * @param string $relatedCollection
     * @param string $type
     * @param bool $twoWay
     * @param string|null $id
     * @param string|null $twoWayKey
     * @param string $onDelete
     * @return bool
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws LimitException
     * @throws StructureException
     */
    public function createRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay = false,
        ?string $id = null,
        ?string $twoWayKey = null,
        string $onDelete = Database::RELATION_MUTATE_RESTRICT
    ): bool {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        $relatedCollection = $this->silent(fn () => $this->getCollection($relatedCollection));

        if ($relatedCollection->isEmpty()) {
            throw new NotFoundException('Related collection not found');
        }

        $id ??= $relatedCollection->getId();

        $twoWayKey ??= $collection->getId();

        $attributes = $collection->getAttribute('attributes', []);
        /** @var array<Document> $attributes */
        foreach ($attributes as $attribute) {
            if (\strtolower($attribute->getId()) === \strtolower($id)) {
                throw new DuplicateException('Attribute already exists');
            }

            if (
                $attribute->getAttribute('type') === self::VAR_RELATIONSHIP
                && \strtolower($attribute->getAttribute('options')['twoWayKey']) === \strtolower($twoWayKey)
                && $attribute->getAttribute('options')['relatedCollection'] === $relatedCollection->getId()
            ) {
                throw new DuplicateException('Related attribute already exists');
            }
        }

        $relationship = new Document([
            '$id' => ID::custom($id),
            'key' => $id,
            'type' => Database::VAR_RELATIONSHIP,
            'required' => false,
            'default' => null,
            'options' => [
                'relatedCollection' => $relatedCollection->getId(),
                'relationType' => $type,
                'twoWay' => $twoWay,
                'twoWayKey' => $twoWayKey,
                'onDelete' => $onDelete,
                'side' => Database::RELATION_SIDE_PARENT,
            ],
        ]);

        $twoWayRelationship = new Document([
            '$id' => ID::custom($twoWayKey),
            'key' => $twoWayKey,
            'type' => Database::VAR_RELATIONSHIP,
            'required' => false,
            'default' => null,
            'options' => [
                'relatedCollection' => $collection->getId(),
                'relationType' => $type,
                'twoWay' => $twoWay,
                'twoWayKey' => $id,
                'onDelete' => $onDelete,
                'side' => Database::RELATION_SIDE_CHILD,
            ],
        ]);

        $this->checkAttribute($collection, $relationship);
        $this->checkAttribute($relatedCollection, $twoWayRelationship);

        $junctionCollection = null;
        if ($type === self::RELATION_MANY_TO_MANY) {
            $junctionCollection = '_' . $collection->getSequence() . '_' . $relatedCollection->getSequence();
            $junctionAttributes = [
                new Document([
                    '$id' => $id,
                    'key' => $id,
                    'type' => self::VAR_STRING,
                    'size' => Database::LENGTH_KEY,
                    'required' => true,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ]),
                new Document([
                    '$id' => $twoWayKey,
                    'key' => $twoWayKey,
                    'type' => self::VAR_STRING,
                    'size' => Database::LENGTH_KEY,
                    'required' => true,
                    'signed' => true,
                    'array' => false,
                    'filters' => [],
                ]),
            ];
            $junctionIndexes = [
                new Document([
                    '$id' => '_index_' . $id,
                    'key' => 'index_' . $id,
                    'type' => self::INDEX_KEY,
                    'attributes' => [$id],
                ]),
                new Document([
                    '$id' => '_index_' . $twoWayKey,
                    'key' => '_index_' . $twoWayKey,
                    'type' => self::INDEX_KEY,
                    'attributes' => [$twoWayKey],
                ]),
            ];
            try {
                $this->silent(fn () => $this->createCollection($junctionCollection, $junctionAttributes, $junctionIndexes));
            } catch (DuplicateException) {
                // Junction metadata already exists from a prior partial failure.
                // Ensure the physical schema also exists.
                try {
                    $this->adapter->createCollection($junctionCollection, $junctionAttributes, $junctionIndexes);
                } catch (DuplicateException) {
                    // Schema already exists — ignore
                }
            }
        }

        $created = false;

        try {
            $created = $this->adapter->createRelationship(
                $collection->getId(),
                $relatedCollection->getId(),
                $type,
                $twoWay,
                $id,
                $twoWayKey
            );

            if (!$created) {
                if ($junctionCollection !== null) {
                    try {
                        $this->silent(fn () => $this->cleanupCollection($junctionCollection));
                    } catch (\Throwable $e) {
                        Console::error("Failed to cleanup junction collection '{$junctionCollection}': " . $e->getMessage());
                    }
                }
                throw new DatabaseException('Failed to create relationship');
            }
        } catch (DuplicateException) {
            // Metadata checks (above) already verified relationship is absent
            // from metadata. A DuplicateException from the adapter means the
            // relationship exists only in physical schema — an orphan from a
            // prior partial failure. Skip creation and proceed to metadata update.
        }

        $collection->setAttribute('attributes', $relationship, Document::SET_TYPE_APPEND);
        $relatedCollection->setAttribute('attributes', $twoWayRelationship, Document::SET_TYPE_APPEND);

        $this->silent(function () use ($collection, $relatedCollection, $type, $twoWay, $id, $twoWayKey, $junctionCollection, $created) {
            $indexesCreated = [];
            try {
                $this->withRetries(function () use ($collection, $relatedCollection) {
                    $this->withTransaction(function () use ($collection, $relatedCollection) {
                        $this->updateDocument(self::METADATA, $collection->getId(), $collection);
                        $this->updateDocument(self::METADATA, $relatedCollection->getId(), $relatedCollection);
                    });
                });
            } catch (\Throwable $e) {
                $this->rollbackAttributeMetadata($collection, [$id]);
                $this->rollbackAttributeMetadata($relatedCollection, [$twoWayKey]);

                if ($created) {
                    try {
                        $this->cleanupRelationship(
                            $collection->getId(),
                            $relatedCollection->getId(),
                            $type,
                            $twoWay,
                            $id,
                            $twoWayKey,
                            Database::RELATION_SIDE_PARENT
                        );
                    } catch (\Throwable $e) {
                        Console::error("Failed to cleanup relationship '{$id}': " . $e->getMessage());
                    }

                    if ($junctionCollection !== null) {
                        try {
                            $this->cleanupCollection($junctionCollection);
                        } catch (\Throwable $e) {
                            Console::error("Failed to cleanup junction collection '{$junctionCollection}': " . $e->getMessage());
                        }
                    }
                }

                throw new DatabaseException('Failed to create relationship: ' . $e->getMessage());
            }

            $indexKey = '_index_' . $id;
            $twoWayIndexKey = '_index_' . $twoWayKey;
            $indexesCreated = [];

            try {
                switch ($type) {
                    case self::RELATION_ONE_TO_ONE:
                        $this->createIndex($collection->getId(), $indexKey, self::INDEX_UNIQUE, [$id]);
                        $indexesCreated[] = ['collection' => $collection->getId(), 'index' => $indexKey];
                        if ($twoWay) {
                            $this->createIndex($relatedCollection->getId(), $twoWayIndexKey, self::INDEX_UNIQUE, [$twoWayKey]);
                            $indexesCreated[] = ['collection' => $relatedCollection->getId(), 'index' => $twoWayIndexKey];
                        }
                        break;
                    case self::RELATION_ONE_TO_MANY:
                        $this->createIndex($relatedCollection->getId(), $twoWayIndexKey, self::INDEX_KEY, [$twoWayKey]);
                        $indexesCreated[] = ['collection' => $relatedCollection->getId(), 'index' => $twoWayIndexKey];
                        break;
                    case self::RELATION_MANY_TO_ONE:
                        $this->createIndex($collection->getId(), $indexKey, self::INDEX_KEY, [$id]);
                        $indexesCreated[] = ['collection' => $collection->getId(), 'index' => $indexKey];
                        break;
                    case self::RELATION_MANY_TO_MANY:
                        // Indexes created on junction collection creation
                        break;
                    default:
                        throw new RelationshipException('Invalid relationship type.');
                }
            } catch (\Throwable $e) {
                foreach ($indexesCreated as $indexInfo) {
                    try {
                        $this->deleteIndex($indexInfo['collection'], $indexInfo['index']);
                    } catch (\Throwable $cleanupError) {
                        Console::error("Failed to cleanup index '{$indexInfo['index']}': " . $cleanupError->getMessage());
                    }
                }

                try {
                    $this->withTransaction(function () use ($collection, $relatedCollection, $id, $twoWayKey) {
                        $attributes = $collection->getAttribute('attributes', []);
                        $collection->setAttribute('attributes', array_filter($attributes, fn ($attr) => $attr->getId() !== $id));
                        $this->updateDocument(self::METADATA, $collection->getId(), $collection);

                        $relatedAttributes = $relatedCollection->getAttribute('attributes', []);
                        $relatedCollection->setAttribute('attributes', array_filter($relatedAttributes, fn ($attr) => $attr->getId() !== $twoWayKey));
                        $this->updateDocument(self::METADATA, $relatedCollection->getId(), $relatedCollection);
                    });
                } catch (\Throwable $cleanupError) {
                    Console::error("Failed to cleanup metadata for relationship '{$id}': " . $cleanupError->getMessage());
                }

                // Cleanup relationship
                try {
                    $this->cleanupRelationship(
                        $collection->getId(),
                        $relatedCollection->getId(),
                        $type,
                        $twoWay,
                        $id,
                        $twoWayKey,
                        Database::RELATION_SIDE_PARENT
                    );
                } catch (\Throwable $cleanupError) {
                    Console::error("Failed to cleanup relationship '{$id}': " . $cleanupError->getMessage());
                }

                if ($junctionCollection !== null) {
                    try {
                        $this->cleanupCollection($junctionCollection);
                    } catch (\Throwable $cleanupError) {
                        Console::error("Failed to cleanup junction collection '{$junctionCollection}': " . $cleanupError->getMessage());
                    }
                }

                throw new DatabaseException('Failed to create relationship indexes: ' . $e->getMessage());
            }
        });

        try {
            $this->trigger(self::EVENT_ATTRIBUTE_CREATE, $relationship);
        } catch (\Throwable $e) {
            // Ignore
        }

        return true;
    }

    /**
     * Update a relationship attribute
     *
     * @param string $collection
     * @param string $id
     * @param string|null $newKey
     * @param string|null $newTwoWayKey
     * @param bool|null $twoWay
     * @param string|null $onDelete
     * @return bool
     * @throws ConflictException
     * @throws DatabaseException
     */
    public function updateRelationship(
        string $collection,
        string $id,
        ?string $newKey = null,
        ?string $newTwoWayKey = null,
        ?bool $twoWay = null,
        ?string $onDelete = null
    ): bool {
        if (
            \is_null($newKey)
            && \is_null($newTwoWayKey)
            && \is_null($twoWay)
            && \is_null($onDelete)
        ) {
            return true;
        }

        $collection = $this->getCollection($collection);
        $attributes = $collection->getAttribute('attributes', []);

        if (
            !\is_null($newKey)
            && \in_array($newKey, \array_map(fn ($attribute) => $attribute['key'], $attributes))
        ) {
            throw new DuplicateException('Relationship already exists');
        }

        $attributeIndex = array_search($id, array_map(fn ($attribute) => $attribute['$id'], $attributes));

        if ($attributeIndex === false) {
            throw new NotFoundException('Relationship not found');
        }

        $attribute = $attributes[$attributeIndex];
        $type = $attribute['options']['relationType'];
        $side = $attribute['options']['side'];

        $relatedCollectionId = $attribute['options']['relatedCollection'];
        $relatedCollection = $this->getCollection($relatedCollectionId);

        // Determine if we need to alter the database (rename columns/indexes)
        $oldAttribute = $attributes[$attributeIndex];
        $oldTwoWayKey = $oldAttribute['options']['twoWayKey'];
        $altering = (!\is_null($newKey) && $newKey !== $id)
            || (!\is_null($newTwoWayKey) && $newTwoWayKey !== $oldTwoWayKey);

        // Validate new keys don't already exist
        if (
            !\is_null($newTwoWayKey)
            && \in_array($newTwoWayKey, \array_map(fn ($attribute) => $attribute['key'], $relatedCollection->getAttribute('attributes', [])))
        ) {
            throw new DuplicateException('Related attribute already exists');
        }

        $actualNewKey = $newKey ?? $id;
        $actualNewTwoWayKey = $newTwoWayKey ?? $oldTwoWayKey;
        $actualTwoWay = $twoWay ?? $oldAttribute['options']['twoWay'];
        $actualOnDelete = $onDelete ?? $oldAttribute['options']['onDelete'];

        $adapterUpdated = false;
        if ($altering) {
            try {
                $adapterUpdated = $this->adapter->updateRelationship(
                    $collection->getId(),
                    $relatedCollection->getId(),
                    $type,
                    $actualTwoWay,
                    $id,
                    $oldTwoWayKey,
                    $side,
                    $actualNewKey,
                    $actualNewTwoWayKey
                );

                if (!$adapterUpdated) {
                    throw new DatabaseException('Failed to update relationship');
                }
            } catch (\Throwable $e) {
                // Check if the rename already happened in schema (orphan from prior
                // partial failure where adapter succeeded but metadata+rollback failed).
                // If the new column names already exist, the prior rename completed.
                if ($this->adapter->getSupportForSchemaAttributes()) {
                    $schemaAttributes = $this->getSchemaAttributes($collection->getId());
                    $filteredNewKey = $this->adapter->filter($actualNewKey);
                    $newKeyExists = false;
                    foreach ($schemaAttributes as $schemaAttr) {
                        if (\strtolower($schemaAttr->getId()) === \strtolower($filteredNewKey)) {
                            $newKeyExists = true;
                            break;
                        }
                    }
                    if ($newKeyExists) {
                        $adapterUpdated = true;
                    } else {
                        throw new DatabaseException("Failed to update relationship '{$id}': " . $e->getMessage(), previous: $e);
                    }
                } else {
                    throw new DatabaseException("Failed to update relationship '{$id}': " . $e->getMessage(), previous: $e);
                }
            }
        }

        try {
            $this->updateAttributeMeta($collection->getId(), $id, function ($attribute) use ($actualNewKey, $actualNewTwoWayKey, $actualTwoWay, $actualOnDelete, $relatedCollection, $type, $side) {
                $attribute->setAttribute('$id', $actualNewKey);
                $attribute->setAttribute('key', $actualNewKey);
                $attribute->setAttribute('options', [
                    'relatedCollection' => $relatedCollection->getId(),
                    'relationType' => $type,
                    'twoWay' => $actualTwoWay,
                    'twoWayKey' => $actualNewTwoWayKey,
                    'onDelete' => $actualOnDelete,
                    'side' => $side,
                ]);
            });

            $this->updateAttributeMeta($relatedCollection->getId(), $oldTwoWayKey, function ($twoWayAttribute) use ($actualNewKey, $actualNewTwoWayKey, $actualTwoWay, $actualOnDelete) {
                $options = $twoWayAttribute->getAttribute('options', []);
                $options['twoWayKey'] = $actualNewKey;
                $options['twoWay'] = $actualTwoWay;
                $options['onDelete'] = $actualOnDelete;

                $twoWayAttribute->setAttribute('$id', $actualNewTwoWayKey);
                $twoWayAttribute->setAttribute('key', $actualNewTwoWayKey);
                $twoWayAttribute->setAttribute('options', $options);
            });

            if ($type === self::RELATION_MANY_TO_MANY) {
                $junction = $this->getJunctionCollection($collection, $relatedCollection, $side);

                $this->updateAttributeMeta($junction, $id, function ($junctionAttribute) use ($actualNewKey) {
                    $junctionAttribute->setAttribute('$id', $actualNewKey);
                    $junctionAttribute->setAttribute('key', $actualNewKey);
                });
                $this->updateAttributeMeta($junction, $oldTwoWayKey, function ($junctionAttribute) use ($actualNewTwoWayKey) {
                    $junctionAttribute->setAttribute('$id', $actualNewTwoWayKey);
                    $junctionAttribute->setAttribute('key', $actualNewTwoWayKey);
                });

                $this->withRetries(fn () => $this->purgeCachedCollection($junction));
            }
        } catch (\Throwable $e) {
            if ($adapterUpdated) {
                try {
                    $this->adapter->updateRelationship(
                        $collection->getId(),
                        $relatedCollection->getId(),
                        $type,
                        $actualTwoWay,
                        $actualNewKey,
                        $actualNewTwoWayKey,
                        $side,
                        $id,
                        $oldTwoWayKey
                    );
                } catch (\Throwable $e) {
                    // Ignore
                }
            }
            throw $e;
        }

        // Update Indexes — wrapped in rollback for consistency with metadata
        $renameIndex = function (string $collection, string $key, string $newKey) {
            $this->updateIndexMeta(
                $collection,
                '_index_' . $key,
                function ($index) use ($newKey) {
                    $index->setAttribute('attributes', [$newKey]);
                }
            );
            $this->silent(
                fn () => $this->renameIndex($collection, '_index_' . $key, '_index_' . $newKey)
            );
        };

        $indexRenamesCompleted = [];

        try {
            switch ($type) {
                case self::RELATION_ONE_TO_ONE:
                    if ($id !== $actualNewKey) {
                        $renameIndex($collection->getId(), $id, $actualNewKey);
                        $indexRenamesCompleted[] = [$collection->getId(), $actualNewKey, $id];
                    }
                    if ($actualTwoWay && $oldTwoWayKey !== $actualNewTwoWayKey) {
                        $renameIndex($relatedCollection->getId(), $oldTwoWayKey, $actualNewTwoWayKey);
                        $indexRenamesCompleted[] = [$relatedCollection->getId(), $actualNewTwoWayKey, $oldTwoWayKey];
                    }
                    break;
                case self::RELATION_ONE_TO_MANY:
                    if ($side === Database::RELATION_SIDE_PARENT) {
                        if ($oldTwoWayKey !== $actualNewTwoWayKey) {
                            $renameIndex($relatedCollection->getId(), $oldTwoWayKey, $actualNewTwoWayKey);
                            $indexRenamesCompleted[] = [$relatedCollection->getId(), $actualNewTwoWayKey, $oldTwoWayKey];
                        }
                    } else {
                        if ($id !== $actualNewKey) {
                            $renameIndex($collection->getId(), $id, $actualNewKey);
                            $indexRenamesCompleted[] = [$collection->getId(), $actualNewKey, $id];
                        }
                    }
                    break;
                case self::RELATION_MANY_TO_ONE:
                    if ($side === Database::RELATION_SIDE_PARENT) {
                        if ($id !== $actualNewKey) {
                            $renameIndex($collection->getId(), $id, $actualNewKey);
                            $indexRenamesCompleted[] = [$collection->getId(), $actualNewKey, $id];
                        }
                    } else {
                        if ($oldTwoWayKey !== $actualNewTwoWayKey) {
                            $renameIndex($relatedCollection->getId(), $oldTwoWayKey, $actualNewTwoWayKey);
                            $indexRenamesCompleted[] = [$relatedCollection->getId(), $actualNewTwoWayKey, $oldTwoWayKey];
                        }
                    }
                    break;
                case self::RELATION_MANY_TO_MANY:
                    $junction = $this->getJunctionCollection($collection, $relatedCollection, $side);

                    if ($id !== $actualNewKey) {
                        $renameIndex($junction, $id, $actualNewKey);
                        $indexRenamesCompleted[] = [$junction, $actualNewKey, $id];
                    }
                    if ($oldTwoWayKey !== $actualNewTwoWayKey) {
                        $renameIndex($junction, $oldTwoWayKey, $actualNewTwoWayKey);
                        $indexRenamesCompleted[] = [$junction, $actualNewTwoWayKey, $oldTwoWayKey];
                    }
                    break;
                default:
                    throw new RelationshipException('Invalid relationship type.');
            }
        } catch (\Throwable $e) {
            // Reverse completed index renames
            foreach (\array_reverse($indexRenamesCompleted) as [$coll, $from, $to]) {
                try {
                    $renameIndex($coll, $from, $to);
                } catch (\Throwable) {
                    // Best effort
                }
            }

            // Reverse attribute metadata
            try {
                $this->updateAttributeMeta($collection->getId(), $actualNewKey, function ($attribute) use ($id, $oldAttribute) {
                    $attribute->setAttribute('$id', $id);
                    $attribute->setAttribute('key', $id);
                    $attribute->setAttribute('options', $oldAttribute['options']);
                });
            } catch (\Throwable) {
                // Best effort
            }

            try {
                $this->updateAttributeMeta($relatedCollection->getId(), $actualNewTwoWayKey, function ($twoWayAttribute) use ($oldTwoWayKey, $id, $oldAttribute) {
                    $options = $twoWayAttribute->getAttribute('options', []);
                    $options['twoWayKey'] = $id;
                    $options['twoWay'] = $oldAttribute['options']['twoWay'];
                    $options['onDelete'] = $oldAttribute['options']['onDelete'];
                    $twoWayAttribute->setAttribute('$id', $oldTwoWayKey);
                    $twoWayAttribute->setAttribute('key', $oldTwoWayKey);
                    $twoWayAttribute->setAttribute('options', $options);
                });
            } catch (\Throwable) {
                // Best effort
            }

            if ($type === self::RELATION_MANY_TO_MANY) {
                $junctionId = $this->getJunctionCollection($collection, $relatedCollection, $side);
                try {
                    $this->updateAttributeMeta($junctionId, $actualNewKey, function ($attr) use ($id) {
                        $attr->setAttribute('$id', $id);
                        $attr->setAttribute('key', $id);
                    });
                } catch (\Throwable) {
                    // Best effort
                }
                try {
                    $this->updateAttributeMeta($junctionId, $actualNewTwoWayKey, function ($attr) use ($oldTwoWayKey) {
                        $attr->setAttribute('$id', $oldTwoWayKey);
                        $attr->setAttribute('key', $oldTwoWayKey);
                    });
                } catch (\Throwable) {
                    // Best effort
                }
            }

            // Reverse adapter update
            if ($adapterUpdated) {
                try {
                    $this->adapter->updateRelationship(
                        $collection->getId(),
                        $relatedCollection->getId(),
                        $type,
                        $oldAttribute['options']['twoWay'],
                        $actualNewKey,
                        $actualNewTwoWayKey,
                        $side,
                        $id,
                        $oldTwoWayKey
                    );
                } catch (\Throwable) {
                    // Best effort
                }
            }

            throw new DatabaseException("Failed to update relationship indexes for '{$id}': " . $e->getMessage(), previous: $e);
        }

        $this->withRetries(fn () => $this->purgeCachedCollection($collection->getId()));
        $this->withRetries(fn () => $this->purgeCachedCollection($relatedCollection->getId()));

        return true;
    }

    /**
     * Delete a relationship attribute
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws StructureException
     */
    public function deleteRelationship(string $collection, string $id): bool
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));
        $attributes = $collection->getAttribute('attributes', []);
        $relationship = null;

        foreach ($attributes as $name => $attribute) {
            if ($attribute['$id'] === $id) {
                $relationship = $attribute;
                unset($attributes[$name]);
                break;
            }
        }

        if (\is_null($relationship)) {
            throw new NotFoundException('Relationship not found');
        }

        $collection->setAttribute('attributes', \array_values($attributes));

        $relatedCollection = $relationship['options']['relatedCollection'];
        $type = $relationship['options']['relationType'];
        $twoWay = $relationship['options']['twoWay'];
        $twoWayKey = $relationship['options']['twoWayKey'];
        $side = $relationship['options']['side'];

        $relatedCollection = $this->silent(fn () => $this->getCollection($relatedCollection));
        $relatedAttributes = $relatedCollection->getAttribute('attributes', []);

        foreach ($relatedAttributes as $name => $attribute) {
            if ($attribute['$id'] === $twoWayKey) {
                unset($relatedAttributes[$name]);
                break;
            }
        }

        $relatedCollection->setAttribute('attributes', \array_values($relatedAttributes));

        $collectionAttributes = $collection->getAttribute('attributes');
        $relatedCollectionAttributes = $relatedCollection->getAttribute('attributes');

        // Delete indexes BEFORE dropping columns to avoid referencing non-existent columns
        // Track deleted indexes for rollback
        $deletedIndexes = [];
        $deletedJunction = null;

        $this->silent(function () use ($collection, $relatedCollection, $type, $twoWay, $id, $twoWayKey, $side, &$deletedIndexes, &$deletedJunction) {
            $indexKey = '_index_' . $id;
            $twoWayIndexKey = '_index_' . $twoWayKey;

            switch ($type) {
                case self::RELATION_ONE_TO_ONE:
                    if ($side === Database::RELATION_SIDE_PARENT) {
                        $this->deleteIndex($collection->getId(), $indexKey);
                        $deletedIndexes[] = ['collection' => $collection->getId(), 'key' => $indexKey, 'type' => self::INDEX_UNIQUE, 'attributes' => [$id]];
                        if ($twoWay) {
                            $this->deleteIndex($relatedCollection->getId(), $twoWayIndexKey);
                            $deletedIndexes[] = ['collection' => $relatedCollection->getId(), 'key' => $twoWayIndexKey, 'type' => self::INDEX_UNIQUE, 'attributes' => [$twoWayKey]];
                        }
                    }
                    if ($side === Database::RELATION_SIDE_CHILD) {
                        $this->deleteIndex($relatedCollection->getId(), $twoWayIndexKey);
                        $deletedIndexes[] = ['collection' => $relatedCollection->getId(), 'key' => $twoWayIndexKey, 'type' => self::INDEX_UNIQUE, 'attributes' => [$twoWayKey]];
                        if ($twoWay) {
                            $this->deleteIndex($collection->getId(), $indexKey);
                            $deletedIndexes[] = ['collection' => $collection->getId(), 'key' => $indexKey, 'type' => self::INDEX_UNIQUE, 'attributes' => [$id]];
                        }
                    }
                    break;
                case self::RELATION_ONE_TO_MANY:
                    if ($side === Database::RELATION_SIDE_PARENT) {
                        $this->deleteIndex($relatedCollection->getId(), $twoWayIndexKey);
                        $deletedIndexes[] = ['collection' => $relatedCollection->getId(), 'key' => $twoWayIndexKey, 'type' => self::INDEX_KEY, 'attributes' => [$twoWayKey]];
                    } else {
                        $this->deleteIndex($collection->getId(), $indexKey);
                        $deletedIndexes[] = ['collection' => $collection->getId(), 'key' => $indexKey, 'type' => self::INDEX_KEY, 'attributes' => [$id]];
                    }
                    break;
                case self::RELATION_MANY_TO_ONE:
                    if ($side === Database::RELATION_SIDE_PARENT) {
                        $this->deleteIndex($collection->getId(), $indexKey);
                        $deletedIndexes[] = ['collection' => $collection->getId(), 'key' => $indexKey, 'type' => self::INDEX_KEY, 'attributes' => [$id]];
                    } else {
                        $this->deleteIndex($relatedCollection->getId(), $twoWayIndexKey);
                        $deletedIndexes[] = ['collection' => $relatedCollection->getId(), 'key' => $twoWayIndexKey, 'type' => self::INDEX_KEY, 'attributes' => [$twoWayKey]];
                    }
                    break;
                case self::RELATION_MANY_TO_MANY:
                    $junction = $this->getJunctionCollection(
                        $collection,
                        $relatedCollection,
                        $side
                    );

                    $deletedJunction = $this->silent(fn () => $this->getDocument(self::METADATA, $junction));
                    $this->deleteDocument(self::METADATA, $junction);
                    break;
                default:
                    throw new RelationshipException('Invalid relationship type.');
            }
        });

        $collection = $this->silent(fn () => $this->getCollection($collection->getId()));
        $relatedCollection = $this->silent(fn () => $this->getCollection($relatedCollection->getId()));
        $collection->setAttribute('attributes', $collectionAttributes);
        $relatedCollection->setAttribute('attributes', $relatedCollectionAttributes);

        $shouldRollback = false;
        try {
            $deleted = $this->adapter->deleteRelationship(
                $collection->getId(),
                $relatedCollection->getId(),
                $type,
                $twoWay,
                $id,
                $twoWayKey,
                $side
            );

            if (!$deleted) {
                throw new DatabaseException('Failed to delete relationship');
            }
            $shouldRollback = true;
        } catch (NotFoundException) {
            // Ignore — relationship already absent from schema
        }

        try {
            $this->withRetries(function () use ($collection, $relatedCollection) {
                $this->silent(function () use ($collection, $relatedCollection) {
                    $this->withTransaction(function () use ($collection, $relatedCollection) {
                        $this->updateDocument(self::METADATA, $collection->getId(), $collection);
                        $this->updateDocument(self::METADATA, $relatedCollection->getId(), $relatedCollection);
                    });
                });
            });
        } catch (\Throwable $e) {
            if ($shouldRollback) {
                // Recreate relationship columns
                try {
                    $this->adapter->createRelationship(
                        $collection->getId(),
                        $relatedCollection->getId(),
                        $type,
                        $twoWay,
                        $id,
                        $twoWayKey
                    );
                } catch (\Throwable) {
                    // Silent rollback — best effort to restore consistency
                }
            }

            // Restore deleted indexes
            foreach ($deletedIndexes as $indexInfo) {
                try {
                    $this->createIndex(
                        $indexInfo['collection'],
                        $indexInfo['key'],
                        $indexInfo['type'],
                        $indexInfo['attributes']
                    );
                } catch (\Throwable) {
                    // Silent rollback — best effort
                }
            }

            // Restore junction collection metadata for M2M
            if ($deletedJunction !== null && !$deletedJunction->isEmpty()) {
                try {
                    $this->silent(fn () => $this->createDocument(self::METADATA, $deletedJunction));
                } catch (\Throwable) {
                    // Silent rollback — best effort
                }
            }

            throw new DatabaseException(
                "Failed to persist metadata after retries for relationship deletion '{$id}': " . $e->getMessage(),
                previous: $e
            );
        }

        $this->withRetries(fn () => $this->purgeCachedCollection($collection->getId()));
        $this->withRetries(fn () => $this->purgeCachedCollection($relatedCollection->getId()));

        try {
            $this->trigger(self::EVENT_ATTRIBUTE_DELETE, $relationship);
        } catch (\Throwable $e) {
            // Ignore
        }

        return true;
    }

    /**
     * Rename Index
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     *
     * @return bool
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws StructureException
     */
    public function renameIndex(string $collection, string $old, string $new): bool
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        $indexes = $collection->getAttribute('indexes', []);

        $index = \in_array($old, \array_map(fn ($index) => $index['$id'], $indexes));

        if ($index === false) {
            throw new NotFoundException('Index not found');
        }

        $indexNew = \in_array($new, \array_map(fn ($index) => $index['$id'], $indexes));

        if ($indexNew !== false) {
            throw new DuplicateException('Index name already used');
        }

        foreach ($indexes as $key => $value) {
            if (isset($value['$id']) && $value['$id'] === $old) {
                $indexes[$key]['key'] = $new;
                $indexes[$key]['$id'] = $new;
                $indexNew = $indexes[$key];
                break;
            }
        }

        $collection->setAttribute('indexes', $indexes);

        $renamed = false;
        try {
            $renamed = $this->adapter->renameIndex($collection->getId(), $old, $new);
            if (!$renamed) {
                throw new DatabaseException('Failed to rename index');
            }
        } catch (\Throwable $e) {
            // Check if the rename already happened in schema (orphan from prior
            // partial failure where rename succeeded but metadata update and
            // rollback both failed). Verify by attempting a reverse rename — if
            // $new exists in schema, the reverse succeeds confirming a prior rename.
            try {
                $this->adapter->renameIndex($collection->getId(), $new, $old);
                // Reverse succeeded — index was at $new. Re-rename to complete.
                $renamed = $this->adapter->renameIndex($collection->getId(), $old, $new);
            } catch (\Throwable) {
                // Reverse also failed — genuine error
                throw new DatabaseException("Failed to rename index '{$old}' to '{$new}': " . $e->getMessage(), previous: $e);
            }
        }

        $this->updateMetadata(
            collection: $collection,
            rollbackOperation: fn () => $this->adapter->renameIndex($collection->getId(), $new, $old),
            shouldRollback: $renamed,
            operationDescription: "index rename '{$old}' to '{$new}'"
        );

        $this->withRetries(fn () => $this->purgeCachedCollection($collection->getId()));

        try {
            $this->trigger(self::EVENT_INDEX_RENAME, $indexNew);
        } catch (\Throwable $e) {
            // Ignore
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
     * @param int $ttl
     *
     * @return bool
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws LimitException
     * @throws StructureException
     * @throws Exception
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths = [], array $orders = [], int $ttl = 1): bool
    {
        if (empty($attributes)) {
            throw new DatabaseException('Missing attributes');
        }

        $collection = $this->silent(fn () => $this->getCollection($collection));
        // index IDs are case-insensitive
        $indexes = $collection->getAttribute('indexes', []);

        /** @var array<Document> $indexes */
        foreach ($indexes as $index) {
            if (\strtolower($index->getId()) === \strtolower($id)) {
                throw new DuplicateException('Index already exists');
            }
        }

        if ($this->adapter->getCountOfIndexes($collection) >= $this->adapter->getLimitForIndexes()) {
            throw new LimitException('Index limit reached. Cannot create new index.');
        }

        /** @var array<Document> $collectionAttributes */
        $collectionAttributes = $collection->getAttribute('attributes', []);
        $indexAttributesWithTypes = [];
        foreach ($attributes as $i => $attr) {
            // Support nested paths on object attributes using dot notation:
            // attribute.key.nestedKey -> base attribute "attribute"
            $baseAttr = $attr;
            if (\str_contains($attr, '.')) {
                $baseAttr = \explode('.', $attr, 2)[0] ?? $attr;
            }

            foreach ($collectionAttributes as $collectionAttribute) {
                if ($collectionAttribute->getAttribute('key') === $baseAttr) {

                    $attributeType = $collectionAttribute->getAttribute('type');
                    $indexAttributesWithTypes[$attr] = $attributeType;

                    /**
                     * mysql does not save length in collection when length = attributes size
                     */
                    if (in_array($attributeType, self::STRING_TYPES)) {
                        if (!empty($lengths[$i]) && $lengths[$i] === $collectionAttribute->getAttribute('size') && $this->adapter->getMaxIndexLength() > 0) {
                            $lengths[$i] = null;
                        }
                    }

                    $isArray = $collectionAttribute->getAttribute('array', false);
                    if ($isArray) {
                        if ($this->adapter->getMaxIndexLength() > 0) {
                            $lengths[$i] = self::MAX_ARRAY_INDEX_LENGTH;
                        }
                        $orders[$i] = null;
                    }
                    break;
                }
            }
        }

        $index = new Document([
            '$id' => ID::custom($id),
            'key' => $id,
            'type' => $type,
            'attributes' => $attributes,
            'lengths' => $lengths,
            'orders' => $orders,
            'ttl' => $ttl
        ]);

        if ($this->validate) {

            $validator = new IndexValidator(
                $collection->getAttribute('attributes', []),
                $collection->getAttribute('indexes', []),
                $this->adapter->getMaxIndexLength(),
                $this->adapter->getInternalIndexesKeys(),
                $this->adapter->getSupportForIndexArray(),
                $this->adapter->getSupportForSpatialIndexNull(),
                $this->adapter->getSupportForSpatialIndexOrder(),
                $this->adapter->getSupportForVectors(),
                $this->adapter->getSupportForAttributes(),
                $this->adapter->getSupportForMultipleFulltextIndexes(),
                $this->adapter->getSupportForIdenticalIndexes(),
                $this->adapter->getSupportForObjectIndexes(),
                $this->adapter->getSupportForTrigramIndex(),
                $this->adapter->getSupportForSpatialAttributes(),
                $this->adapter->getSupportForIndex(),
                $this->adapter->getSupportForUniqueIndex(),
                $this->adapter->getSupportForFulltextIndex(),
                $this->adapter->getSupportForTTLIndexes(),
                $this->adapter->getSupportForObject()
            );
            if (!$validator->isValid($index)) {
                throw new IndexException($validator->getDescription());
            }
        }

        $created = false;
        $existsInSchema = false;

        if ($this->adapter->getSupportForSchemaIndexes()
            && !($this->adapter->getSharedTables() && $this->isMigrating())) {
            $schemaIndexes = $this->getSchemaIndexes($collection->getId());
            $filteredId = $this->adapter->filter($id);

            foreach ($schemaIndexes as $schemaIndex) {
                if (\strtolower($schemaIndex->getId()) === \strtolower($filteredId)) {
                    $schemaColumns = $schemaIndex->getAttribute('columns', []);
                    $schemaLengths = $schemaIndex->getAttribute('lengths', []);

                    $filteredAttributes = \array_map(fn ($a) => $this->adapter->filter($a), $attributes);
                    $match = ($schemaColumns === $filteredAttributes && $schemaLengths === $lengths);

                    if ($match) {
                        $existsInSchema = true;
                    } else {
                        // Orphan index with wrong definition — drop so it
                        // gets recreated with the correct shape.
                        try {
                            $this->adapter->deleteIndex($collection->getId(), $id);
                        } catch (NotFoundException) {
                        }
                    }
                    break;
                }
            }
        }

        if (!$existsInSchema) {
            try {
                $created = $this->adapter->createIndex($collection->getId(), $id, $type, $attributes, $lengths, $orders, $indexAttributesWithTypes, [], $ttl);

                if (!$created) {
                    throw new DatabaseException('Failed to create index');
                }
            } catch (UniqueException $e) {
                // Existing rows violate the unique constraint, so no index was built.
                throw $e;
            } catch (DuplicateException) {
                // Metadata check (lines above) already verified index is absent
                // from metadata. A DuplicateException from the adapter means the
                // index exists only in physical schema — an orphan from a prior
                // partial failure. Skip creation and proceed to metadata update.
            }
        }

        $collection->setAttribute('indexes', $index, Document::SET_TYPE_APPEND);

        $this->updateMetadata(
            collection: $collection,
            rollbackOperation: fn () => $this->cleanupIndex($collection->getId(), $id),
            shouldRollback: $created,
            operationDescription: "index creation '{$id}'"
        );

        $this->trigger(self::EVENT_INDEX_CREATE, $index);

        return true;
    }

    /**
     * Delete Index
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws StructureException
     */
    public function deleteIndex(string $collection, string $id): bool
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        $indexes = $collection->getAttribute('indexes', []);

        $indexDeleted = null;
        foreach ($indexes as $key => $value) {
            if (isset($value['$id']) && $value['$id'] === $id) {
                $indexDeleted = $value;
                unset($indexes[$key]);
            }
        }

        if (\is_null($indexDeleted)) {
            throw new NotFoundException('Index not found');
        }

        $shouldRollback = false;
        $deleted = false;
        try {
            $deleted = $this->adapter->deleteIndex($collection->getId(), $id);

            if (!$deleted) {
                throw new DatabaseException('Failed to delete index');
            }
            $shouldRollback = true;
        } catch (NotFoundException) {
            // Index already absent from schema; treat as deleted
            $deleted = true;
        }

        $collection->setAttribute('indexes', \array_values($indexes));

        // Build indexAttributeTypes from collection attributes for rollback
        /** @var array<Document> $collectionAttributes */
        $collectionAttributes = $collection->getAttribute('attributes', []);
        $indexAttributeTypes = [];
        foreach ($indexDeleted->getAttribute('attributes', []) as $attr) {
            $baseAttr = \str_contains($attr, '.') ? \explode('.', $attr, 2)[0] : $attr;
            foreach ($collectionAttributes as $collectionAttribute) {
                if ($collectionAttribute->getAttribute('key') === $baseAttr) {
                    $indexAttributeTypes[$attr] = $collectionAttribute->getAttribute('type');
                    break;
                }
            }
        }

        $this->updateMetadata(
            collection: $collection,
            rollbackOperation: fn () => $this->adapter->createIndex(
                $collection->getId(),
                $id,
                $indexDeleted->getAttribute('type'),
                $indexDeleted->getAttribute('attributes', []),
                $indexDeleted->getAttribute('lengths', []),
                $indexDeleted->getAttribute('orders', []),
                $indexAttributeTypes,
                [],
                $indexDeleted->getAttribute('ttl', 1)
            ),
            shouldRollback: $shouldRollback,
            operationDescription: "index deletion '{$id}'",
            silentRollback: true
        );


        try {
            $this->trigger(self::EVENT_INDEX_DELETE, $indexDeleted);
        } catch (\Throwable $e) {
            // Ignore
        }

        return $deleted;
    }

    /**
     * Get Document
     *
     * @param string $collection
     * @param string $id
     * @param Query[] $queries
     * @param bool $forUpdate
     * @return Document
     * @throws NotFoundException
     * @throws QueryException
     * @throws Exception
     */
    public function getDocument(string $collection, string $id, array $queries = [], bool $forUpdate = false): Document
    {
        if ($collection === self::METADATA && $id === self::METADATA) {
            return new Document(self::COLLECTION);
        }

        if (empty($collection)) {
            throw new NotFoundException('Collection not found');
        }

        if (empty($id)) {
            return $this->createDocumentInstance($collection, []);
        }

        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        $attributes = $collection->getAttribute('attributes', []);

        $this->checkQueryTypes($queries);

        if ($this->validate) {
            $validator = new DocumentValidator($attributes, $this->adapter->getSupportForAttributes());
            if (!$validator->isValid($queries)) {
                throw new QueryException($validator->getDescription());
            }
        }

        $relationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn (Document $attribute) => $attribute->getAttribute('type') === self::VAR_RELATIONSHIP
        );

        $selects = Query::groupByType($queries)['selections'];
        $selections = $this->validateSelections($collection, $selects);
        $nestedSelections = $this->processRelationshipQueries($relationships, $queries);

        $documentSecurity = $collection->getAttribute('documentSecurity', false);

        [$collectionKey, $documentKey, $hashKey] = $this->getCacheKeys(
            $collection->getId(),
            $id,
            $selections
        );

        // A locking read must observe the current row, not a cached copy:
        // updateDocument merges the changes into this read and writes the result
        // back, so serving it from a stale cache would persist the staleness.
        $cached = null;
        if (!$forUpdate) {
            try {
                $cached = $this->cache->load($documentKey, self::TTL, $hashKey);
            } catch (Exception $e) {
                Console::warning('Warning: Failed to get document from cache: ' . $e->getMessage());
            }
        }

        // Negative cache hit
        if (\is_array($cached) && isset($cached[self::CACHE_EMPTY_MARKER])) {
            return $this->createDocumentInstance($collection->getId(), []);
        }

        if ($cached) {
            $document = $this->createDocumentInstance($collection->getId(), $cached);

            // JSON serialization in cache backends collapses floats with zero
            // fractions to ints. Re-cast so cached and freshly-loaded documents
            // compare equal under strict equality (e.g. in updateDocument).
            $document = $this->casting($collection, $document);

            if ($collection->getId() !== self::METADATA) {

                if (!$this->authorization->isValid(new Input(self::PERMISSION_READ, [
                    ...$collection->getRead(),
                    ...($documentSecurity ? $document->getRead() : [])
                ]))) {
                    return $this->createDocumentInstance($collection->getId(), []);
                }
            }

            $this->trigger(self::EVENT_DOCUMENT_READ, $document);

            if ($this->isTtlExpired($collection, $document)) {
                return $this->createDocumentInstance($collection->getId(), []);
            }

            return $document;
        }

        // Capture the generation before reading: if a concurrent purge advances
        // it, saveWithLease() below rejects this now-stale value. '0' means no lease.
        $generation = '0';
        if (!$forUpdate) {
            try {
                $generation = $this->cache->getGeneration($documentKey);
            } catch (Exception $e) {
                Console::warning('Warning: Failed to get cache generation: ' . $e->getMessage());
            }
        }

        $document = $this->adapter->getDocument(
            $collection,
            $id,
            $queries,
            $forUpdate
        );

        if ($document->isEmpty()) {
            if (!$forUpdate && empty($relationships)) {
                try {
                    $marker = [self::CACHE_EMPTY_MARKER => true];

                    if ($this->cache->saveWithLease($documentKey, $marker, $hashKey, $generation) !== false) {
                        $this->cache->save($collectionKey, 'empty', $documentKey);
                    }
                } catch (Exception $e) {
                    Console::warning('Failed to save empty document to cache: ' . $e->getMessage());
                }
            }

            return $this->createDocumentInstance($collection->getId(), []);
        }

        if ($this->isTtlExpired($collection, $document)) {
            return $this->createDocumentInstance($collection->getId(), []);
        }

        $document = $this->adapter->castingAfter($collection, $document);

        // Convert to custom document type if mapped
        if (isset($this->documentTypes[$collection->getId()])) {
            $document = $this->createDocumentInstance($collection->getId(), $document->getArrayCopy());
        }

        $document->setAttribute('$collection', $collection->getId());

        if ($collection->getId() !== self::METADATA) {
            if (!$this->authorization->isValid(new Input(self::PERMISSION_READ, [
                ...$collection->getRead(),
                ...($documentSecurity ? $document->getRead() : [])
            ]))) {
                return $this->createDocumentInstance($collection->getId(), []);
            }
        }

        $document = $this->casting($collection, $document);
        $document = $this->decode($collection, $document, $selections);

        // Skip relationship population if we're in batch mode (relationships will be populated later)
        if (!$this->inBatchRelationshipPopulation && $this->resolveRelationships && !empty($relationships) && (empty($selects) || !empty($nestedSelections))) {
            $documents = $this->silent(fn () => $this->populateDocumentsRelationships([$document], $collection, $this->relationshipFetchDepth, $nestedSelections));
            $document = $documents[0];
        }

        $relationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute['type'] === Database::VAR_RELATIONSHIP
        );

        // Don't save to cache if it's part of a relationship, or if this is a
        // locking read: a forUpdate read happens inside an open transaction, and
        // caching the pre-commit row would poison the cache for other readers.
        if (!$forUpdate && empty($relationships)) {
            try {
                // Index for invalidation only when the value was actually cached.
                if ($this->cache->saveWithLease($documentKey, $document->getArrayCopy(), $hashKey, $generation) !== false) {
                    $this->cache->save($collectionKey, 'empty', $documentKey);
                }
            } catch (Exception $e) {
                Console::warning('Failed to save document to cache: ' . $e->getMessage());
            }
        }

        $this->trigger(self::EVENT_DOCUMENT_READ, $document);

        return $document;
    }

    private function isTtlExpired(Document $collection, Document $document): bool
    {
        if (!$this->adapter->getSupportForTTLIndexes()) {
            return false;
        }
        foreach ($collection->getAttribute('indexes', []) as $index) {
            if ($index->getAttribute('type') !== self::INDEX_TTL) {
                continue;
            }
            $ttlSeconds = (int) $index->getAttribute('ttl', 0);
            $ttlAttr    = $index->getAttribute('attributes')[0] ?? null;
            if ($ttlSeconds <= 0 || !$ttlAttr) {
                return false;
            }
            $val = $document->getAttribute($ttlAttr);
            if (is_string($val)) {
                try {
                    $start = new \DateTime($val);
                    return (new \DateTime()) > (clone $start)->modify("+{$ttlSeconds} seconds");
                } catch (\Throwable) {
                    return false;
                }
            }
        }
        return false;
    }

    /**
     * Populate relationships for an array of documents with breadth-first traversal
     *
     * @param array<Document> $documents
     * @param Document $collection
     * @param int $relationshipFetchDepth
     * @param array<string, array<Query>> $selects
     * @return array<Document>
     * @throws DatabaseException
     */
    private function populateDocumentsRelationships(
        array $documents,
        Document $collection,
        int $relationshipFetchDepth = 0,
        array $selects = []
    ): array {
        // Prevent nested relationship population during fetches
        $this->inBatchRelationshipPopulation = true;

        try {
            $queue = [
                [
                    'documents' => $documents,
                    'collection' => $collection,
                    'depth' => $relationshipFetchDepth,
                    'selects' => $selects,
                    'skipKey' => null, // No back-reference to skip at top level
                    'hasExplicitSelects' => !empty($selects) // Track if we're in explicit select mode
                ]
            ];

            $currentDepth = $relationshipFetchDepth;

            while (!empty($queue) && $currentDepth < self::RELATION_MAX_DEPTH) {
                $nextQueue = [];

                foreach ($queue as $item) {
                    $docs = $item['documents'];
                    $coll = $item['collection'];
                    $sels = $item['selects'];
                    $skipKey = $item['skipKey'] ?? null;
                    $parentHasExplicitSelects = $item['hasExplicitSelects'];

                    if (empty($docs)) {
                        continue;
                    }

                    $attributes = $coll->getAttribute('attributes', []);
                    $relationships = [];

                    foreach ($attributes as $attribute) {
                        if ($attribute['type'] === Database::VAR_RELATIONSHIP) {
                            // Skip the back-reference relationship that brought us here
                            if ($attribute['key'] === $skipKey) {
                                continue;
                            }

                            // Include relationship if:
                            // 1. No explicit selects (fetch all) OR
                            // 2. Relationship is explicitly selected
                            if (!$parentHasExplicitSelects || \array_key_exists($attribute['key'], $sels)) {
                                $relationships[] = $attribute;
                            }
                        }
                    }

                    foreach ($relationships as $relationship) {
                        $key = $relationship['key'];
                        $queries = $sels[$key] ?? [];
                        $relationship->setAttribute('collection', $coll->getId());
                        $isAtMaxDepth = ($currentDepth + 1) >= self::RELATION_MAX_DEPTH;

                        // If we're at max depth, remove this relationship from source documents and skip
                        if ($isAtMaxDepth) {
                            foreach ($docs as $doc) {
                                $doc->removeAttribute($key);
                            }
                            continue;
                        }

                        $relatedDocs = $this->populateSingleRelationshipBatch(
                            $docs,
                            $relationship,
                            $queries
                        );

                        // Get two-way relationship info
                        $twoWay = $relationship['options']['twoWay'];
                        $twoWayKey = $relationship['options']['twoWayKey'];

                        // Queue if:
                        // 1. No explicit selects (fetch all recursively), OR
                        // 2. Explicit nested selects for this relationship
                        $hasNestedSelectsForThisRel = isset($sels[$key]);
                        $shouldQueue = !empty($relatedDocs) &&
                            ($hasNestedSelectsForThisRel || !$parentHasExplicitSelects);

                        if ($shouldQueue) {
                            $relatedCollectionId = $relationship['options']['relatedCollection'];
                            $relatedCollection = $this->silent(fn () => $this->getCollection($relatedCollectionId));

                            if (!$relatedCollection->isEmpty()) {
                                // Get nested selections for this relationship
                                $relationshipQueries = $hasNestedSelectsForThisRel ? $sels[$key] : [];

                                // Extract nested selections for the related collection
                                $relatedCollectionRelationships = $relatedCollection->getAttribute('attributes', []);
                                $relatedCollectionRelationships = \array_filter(
                                    $relatedCollectionRelationships,
                                    fn ($attr) => $attr['type'] === Database::VAR_RELATIONSHIP
                                );

                                $nextSelects = $this->processRelationshipQueries($relatedCollectionRelationships, $relationshipQueries);

                                // If parent has explicit selects, child inherits that mode
                                // (even if nextSelects is empty, we're still in explicit mode)
                                $childHasExplicitSelects = $parentHasExplicitSelects;

                                $nextQueue[] = [
                                    'documents' => $relatedDocs,
                                    'collection' => $relatedCollection,
                                    'depth' => $currentDepth + 1,
                                    'selects' => $nextSelects,
                                    'skipKey' => $twoWay ? $twoWayKey : null, // Skip the back-reference at next depth
                                    'hasExplicitSelects' => $childHasExplicitSelects
                                ];
                            }
                        }

                        // Remove back-references for two-way relationships
                        // Back-references are always removed to prevent circular references
                        if ($twoWay && !empty($relatedDocs)) {
                            foreach ($relatedDocs as $relatedDoc) {
                                $relatedDoc->removeAttribute($twoWayKey);
                            }
                        }
                    }
                }

                $queue = $nextQueue;
                $currentDepth++;
            }
        } finally {
            $this->inBatchRelationshipPopulation = false;
        }

        return $documents;
    }

    /**
     * Populate a single relationship type for all documents in batch
     * Returns all related documents that were populated
     *
     * @param array<Document> $documents
     * @param Document $relationship
     * @param array<Query> $queries
     * @return array<Document>
     * @throws DatabaseException
     */
    private function populateSingleRelationshipBatch(
        array $documents,
        Document $relationship,
        array $queries
    ): array {
        return match ($relationship['options']['relationType']) {
            Database::RELATION_ONE_TO_ONE => $this->populateOneToOneRelationshipsBatch($documents, $relationship, $queries),
            Database::RELATION_ONE_TO_MANY => $this->populateOneToManyRelationshipsBatch($documents, $relationship, $queries),
            Database::RELATION_MANY_TO_ONE => $this->populateManyToOneRelationshipsBatch($documents, $relationship, $queries),
            Database::RELATION_MANY_TO_MANY => $this->populateManyToManyRelationshipsBatch($documents, $relationship, $queries),
            default => [],
        };
    }

    /**
     * Populate one-to-one relationships in batch
     * Returns all related documents that were fetched
     *
     * @param array<Document> $documents
     * @param Document $relationship
     * @param array<Query> $queries
     * @return array<Document>
     * @throws DatabaseException
     */
    private function populateOneToOneRelationshipsBatch(array $documents, Document $relationship, array $queries): array
    {
        $key = $relationship['key'];
        $relatedCollection = $this->getCollection($relationship['options']['relatedCollection']);

        $relatedIds = [];
        $documentsByRelatedId = [];

        foreach ($documents as $document) {
            $value = $document->getAttribute($key);
            if (!\is_null($value)) {
                // Skip if value is already populated
                if ($value instanceof Document) {
                    continue;
                }

                // For one-to-one, multiple documents can reference the same related ID
                $relatedIds[] = $value;
                if (!isset($documentsByRelatedId[$value])) {
                    $documentsByRelatedId[$value] = [];
                }
                $documentsByRelatedId[$value][] = $document;
            }
        }

        if (empty($relatedIds)) {
            return [];
        }

        $uniqueRelatedIds = \array_unique($relatedIds);
        $relatedDocuments = [];

        // Process in chunks to avoid exceeding query value limits
        foreach (\array_chunk($uniqueRelatedIds, \max(1, $this->maxQueryValues)) as $chunk) {
            $chunkDocs = $this->find($relatedCollection->getId(), [
                Query::equal('$id', $chunk),
                Query::limit(PHP_INT_MAX),
                ...$queries
            ]);
            \array_push($relatedDocuments, ...$chunkDocs);
        }

        // Index related documents by ID for quick lookup
        $relatedById = [];
        foreach ($relatedDocuments as $related) {
            $relatedById[$related->getId()] = $related;
        }

        // Assign related documents to their parent documents
        foreach ($documentsByRelatedId as $relatedId => $docs) {
            if (isset($relatedById[$relatedId])) {
                // Set the relationship for all documents that reference this related ID
                foreach ($docs as $document) {
                    $document->setAttribute($key, $relatedById[$relatedId]);
                }
            } else {
                // If related document not found, set to empty Document instead of leaving the string ID
                foreach ($docs as $document) {
                    $document->setAttribute($key, new Document());
                }
            }
        }

        return $relatedDocuments;
    }

    /**
     * Populate one-to-many relationships in batch
     * Returns all related documents that were fetched
     *
     * @param array<Document> $documents
     * @param Document $relationship
     * @param array<Query> $queries
     * @return array<Document>
     * @throws DatabaseException
     */
    private function populateOneToManyRelationshipsBatch(
        array $documents,
        Document $relationship,
        array $queries,
    ): array {
        $key = $relationship['key'];
        $twoWay = $relationship['options']['twoWay'];
        $twoWayKey = $relationship['options']['twoWayKey'];
        $side = $relationship['options']['side'];
        $relatedCollection = $this->getCollection($relationship['options']['relatedCollection']);

        if ($side === Database::RELATION_SIDE_CHILD) {
            // Child side - treat like one-to-one
            if (!$twoWay) {
                foreach ($documents as $document) {
                    $document->removeAttribute($key);
                }
                return [];
            }
            return $this->populateOneToOneRelationshipsBatch($documents, $relationship, $queries);
        }

        // Parent side - fetch multiple related documents
        $parentIds = [];
        foreach ($documents as $document) {
            $parentId = $document->getId();
            $parentIds[] = $parentId;
        }

        $parentIds = \array_unique($parentIds);

        if (empty($parentIds)) {
            return [];
        }

        // For batch relationship population, we need to fetch documents with all attributes
        // to enable proper grouping by back-reference, then apply selects afterward
        $selectQueries = [];
        $otherQueries = [];
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_SELECT) {
                $selectQueries[] = $query;
            } else {
                $otherQueries[] = $query;
            }
        }

        $relatedDocuments = [];

        foreach (\array_chunk($parentIds, \max(1, $this->maxQueryValues)) as $chunk) {
            $chunkDocs = $this->find($relatedCollection->getId(), [
                Query::equal($twoWayKey, $chunk),
                Query::limit(PHP_INT_MAX),
                ...$otherQueries
            ]);
            \array_push($relatedDocuments, ...$chunkDocs);
        }

        // Group related documents by parent ID
        $relatedByParentId = [];
        foreach ($relatedDocuments as $related) {
            $parentId = $related->getAttribute($twoWayKey);
            if (!\is_null($parentId)) {
                // Handle case where parentId might be a Document object instead of string
                $parentKey = $parentId instanceof Document
                    ? $parentId->getId()
                    : $parentId;

                if (!isset($relatedByParentId[$parentKey])) {
                    $relatedByParentId[$parentKey] = [];
                }
                // We don't remove the back-reference here because documents may be reused across fetches
                // Cycles are prevented by depth limiting in breadth-first traversal
                $relatedByParentId[$parentKey][] = $related;
            }
        }

        $this->applySelectFiltersToDocuments($relatedDocuments, $selectQueries);

        // Assign related documents to their parent documents
        foreach ($documents as $document) {
            $parentId = $document->getId();
            $relatedDocs = $relatedByParentId[$parentId] ?? [];
            $document->setAttribute($key, $relatedDocs);
        }

        return $relatedDocuments;
    }

    /**
     * Populate many-to-one relationships in batch
     *
     * @param array<Document> $documents
     * @param Document $relationship
     * @param array<Query> $queries
     * @return array<Document>
     * @throws DatabaseException
     */
    private function populateManyToOneRelationshipsBatch(
        array $documents,
        Document $relationship,
        array $queries,
    ): array {
        $key = $relationship['key'];
        $twoWay = $relationship['options']['twoWay'];
        $twoWayKey = $relationship['options']['twoWayKey'];
        $side = $relationship['options']['side'];
        $relatedCollection = $this->getCollection($relationship['options']['relatedCollection']);

        if ($side === Database::RELATION_SIDE_PARENT) {
            // Parent side - treat like one-to-one
            return $this->populateOneToOneRelationshipsBatch($documents, $relationship, $queries);
        }

        // Child side - fetch multiple related documents
        if (!$twoWay) {
            foreach ($documents as $document) {
                $document->removeAttribute($key);
            }
            return [];
        }

        $childIds = [];
        foreach ($documents as $document) {
            $childId = $document->getId();
            $childIds[] = $childId;
        }

        $childIds = array_unique($childIds);

        if (empty($childIds)) {
            return [];
        }

        $selectQueries = [];
        $otherQueries = [];
        foreach ($queries as $query) {
            if ($query->getMethod() === Query::TYPE_SELECT) {
                $selectQueries[] = $query;
            } else {
                $otherQueries[] = $query;
            }
        }

        $relatedDocuments = [];

        foreach (\array_chunk($childIds, \max(1, $this->maxQueryValues)) as $chunk) {
            $chunkDocs = $this->find($relatedCollection->getId(), [
                Query::equal($twoWayKey, $chunk),
                Query::limit(PHP_INT_MAX),
                ...$otherQueries
            ]);
            \array_push($relatedDocuments, ...$chunkDocs);
        }

        // Group related documents by child ID
        $relatedByChildId = [];
        foreach ($relatedDocuments as $related) {
            $childId = $related->getAttribute($twoWayKey);
            if (!\is_null($childId)) {
                // Handle case where childId might be a Document object instead of string
                $childKey = $childId instanceof Document
                    ? $childId->getId()
                    : $childId;

                if (!isset($relatedByChildId[$childKey])) {
                    $relatedByChildId[$childKey] = [];
                }
                // We don't remove the back-reference here because documents may be reused across fetches
                // Cycles are prevented by depth limiting in breadth-first traversal
                $relatedByChildId[$childKey][] = $related;
            }
        }

        $this->applySelectFiltersToDocuments($relatedDocuments, $selectQueries);

        foreach ($documents as $document) {
            $childId = $document->getId();
            $document->setAttribute($key, $relatedByChildId[$childId] ?? []);
        }

        return $relatedDocuments;
    }

    /**
     * Populate many-to-many relationships in batch
     *
     * @param array<Document> $documents
     * @param Document $relationship
     * @param array<Query> $queries
     * @return array<Document>
     * @throws DatabaseException
     */
    private function populateManyToManyRelationshipsBatch(
        array $documents,
        Document $relationship,
        array $queries
    ): array {
        $key = $relationship['key'];
        $twoWay = $relationship['options']['twoWay'];
        $twoWayKey = $relationship['options']['twoWayKey'];
        $side = $relationship['options']['side'];
        $relatedCollection = $this->getCollection($relationship['options']['relatedCollection']);
        $collection = $this->getCollection($relationship->getAttribute('collection'));

        if (!$twoWay && $side === Database::RELATION_SIDE_CHILD) {
            return [];
        }

        $documentIds = [];
        foreach ($documents as $document) {
            $documentId = $document->getId();
            $documentIds[] = $documentId;
        }

        $documentIds = array_unique($documentIds);

        if (empty($documentIds)) {
            return [];
        }

        $junction = $this->getJunctionCollection($collection, $relatedCollection, $side);

        $junctions = [];

        foreach (\array_chunk($documentIds, \max(1, $this->maxQueryValues)) as $chunk) {
            $chunkJunctions = $this->skipRelationships(fn () => $this->find($junction, [
                Query::equal($twoWayKey, $chunk),
                Query::limit(PHP_INT_MAX)
            ]));
            \array_push($junctions, ...$chunkJunctions);
        }

        $relatedIds = [];
        $junctionsByDocumentId = [];

        foreach ($junctions as $junctionDoc) {
            $documentId = $junctionDoc->getAttribute($twoWayKey);
            $relatedId = $junctionDoc->getAttribute($key);

            if (!\is_null($documentId) && !\is_null($relatedId)) {
                if (!isset($junctionsByDocumentId[$documentId])) {
                    $junctionsByDocumentId[$documentId] = [];
                }
                $junctionsByDocumentId[$documentId][] = $relatedId;
                $relatedIds[] = $relatedId;
            }
        }

        $related = [];
        $allRelatedDocs = [];
        if (!empty($relatedIds)) {
            $uniqueRelatedIds = array_unique($relatedIds);
            $foundRelated = [];

            foreach (\array_chunk($uniqueRelatedIds, \max(1, $this->maxQueryValues)) as $chunk) {
                $chunkDocs = $this->find($relatedCollection->getId(), [
                    Query::equal('$id', $chunk),
                    Query::limit(PHP_INT_MAX),
                    ...$queries
                ]);
                \array_push($foundRelated, ...$chunkDocs);
            }

            $allRelatedDocs = $foundRelated;

            $relatedById = [];
            foreach ($foundRelated as $doc) {
                $relatedById[$doc->getId()] = $doc;
            }

            // Build final related arrays maintaining junction order
            foreach ($junctionsByDocumentId as $documentId => $relatedDocIds) {
                $documentRelated = [];
                foreach ($relatedDocIds as $relatedId) {
                    if (isset($relatedById[$relatedId])) {
                        $documentRelated[] = $relatedById[$relatedId];
                    }
                }
                $related[$documentId] = $documentRelated;
            }
        }

        foreach ($documents as $document) {
            $documentId = $document->getId();
            $document->setAttribute($key, $related[$documentId] ?? []);
        }

        return $allRelatedDocs;
    }

    /**
     * Apply select filters to documents after fetching
     *
     * Filters document attributes based on select queries while preserving internal attributes.
     * This is used in batch relationship population to apply selects after grouping.
     *
     * @param array<Document> $documents Documents to filter
     * @param array<Query> $selectQueries Select query objects
     * @return void
     */
    private function applySelectFiltersToDocuments(array $documents, array $selectQueries): void
    {
        if (empty($selectQueries) || empty($documents)) {
            return;
        }

        // Collect all attributes to keep from select queries
        $attributesToKeep = [];
        foreach ($selectQueries as $selectQuery) {
            foreach ($selectQuery->getValues() as $value) {
                $attributesToKeep[$value] = true;
            }
        }

        // Early return if wildcard selector present
        if (isset($attributesToKeep['*'])) {
            return;
        }

        // Always preserve internal attributes (use hashmap for O(1) lookup)
        $internalKeys = \array_map(fn ($attr) => $attr['$id'], $this->getInternalAttributes());
        foreach ($internalKeys as $key) {
            $attributesToKeep[$key] = true;
        }

        foreach ($documents as $doc) {
            $allKeys = \array_keys($doc->getArrayCopy());
            foreach ($allKeys as $attrKey) {
                // Keep if: explicitly selected OR is internal attribute ($ prefix)
                if (!isset($attributesToKeep[$attrKey]) && !\str_starts_with($attrKey, '$')) {
                    $doc->removeAttribute($attrKey);
                }
            }
        }
    }

    /**
     * Create Document
     *
     * @param string $collection
     * @param Document $document
     * @return Document
     * @throws AuthorizationException
     * @throws DatabaseException
     * @throws StructureException
     */
    public function createDocument(string $collection, Document $document): Document
    {
        if (
            $collection !== self::METADATA
            && $this->adapter->getSharedTables()
            && !$this->adapter->getTenantPerDocument()
            && empty($this->adapter->getTenant())
        ) {
            throw new DatabaseException('Missing tenant. Tenant must be set when table sharing is enabled.');
        }

        if (
            !$this->adapter->getSharedTables()
            && $this->adapter->getTenantPerDocument()
        ) {
            throw new DatabaseException('Shared tables must be enabled if tenant per document is enabled.');
        }

        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->getId() !== self::METADATA) {
            $isValid = $this->authorization->isValid(new Input(self::PERMISSION_CREATE, $collection->getCreate()));
            if (!$isValid) {
                throw new AuthorizationException($this->authorization->getDescription());
            }
        }

        $time = DateTime::now();

        $createdAt = $document->getCreatedAt();
        $updatedAt = $document->getUpdatedAt();

        $document
            ->setAttribute('$id', empty($document->getId()) ? ID::unique() : $document->getId())
            ->setAttribute('$collection', $collection->getId())
            ->setAttribute('$createdAt', ($createdAt === null || !$this->preserveDates) ? $time : $createdAt)
            ->setAttribute('$updatedAt', ($updatedAt === null || !$this->preserveDates) ? $time : $updatedAt);

        if (empty($document->getPermissions())) {
            $document->setAttribute('$permissions', []);
        }

        if ($this->adapter->getSharedTables()) {
            if ($this->adapter->getTenantPerDocument()) {
                if (
                    $collection->getId() !== static::METADATA
                    && $document->getTenant() === null
                ) {
                    throw new DatabaseException('Missing tenant. Tenant must be set when tenant per document is enabled.');
                }
            } else {
                $document->setAttribute('$tenant', $this->adapter->getTenant());
            }
        }

        $document = $this->encode($collection, $document);

        if ($this->validate) {
            $validator = new Permissions();
            if (!$validator->isValid($document->getPermissions())) {
                throw new DatabaseException($validator->getDescription());
            }
        }

        if ($this->validate) {
            $structure = new Structure(
                $collection,
                $this->adapter->getIdAttributeType(),
                $this->adapter->getMinDateTime(),
                $this->adapter->getMaxDateTime(),
                $this->adapter->getSupportForAttributes(),
                supportUnsignedBigInt: $this->adapter->getSupportForUnsignedBigInt(),
                currentDocument: null
            );
            if (!$structure->isValid($document)) {
                throw new StructureException($structure->getDescription());
            }
        }

        $document = $this->adapter->castingBefore($collection, $document);

        $document = $this->withTransaction(function () use ($collection, $document) {
            if ($this->resolveRelationships) {
                $document = $this->silent(fn () => $this->createDocumentRelationships($collection, $document));
            }
            return $this->adapter->createDocument($collection, $document);
        });

        // Clear any negative-cache entry for this id: a prior read may have
        // recorded it as missing before this insert committed.
        $this->withDocumentTenant($document, fn () => $this->purgeCachedDocumentInternal($collection->getId(), $document->getId()));

        if (!$this->inBatchRelationshipPopulation && $this->resolveRelationships) {
            // Use the write stack depth for proper MAX_DEPTH enforcement during creation
            $fetchDepth = count($this->relationshipWriteStack);
            $documents = $this->silent(fn () => $this->populateDocumentsRelationships([$document], $collection, $fetchDepth));
            $document = $this->adapter->castingAfter($collection, $documents[0]);
        }

        $document = $this->casting($collection, $document);
        $document = $this->decode($collection, $document);

        // Convert to custom document type if mapped
        if (isset($this->documentTypes[$collection->getId()])) {
            $document = $this->createDocumentInstance($collection->getId(), $document->getArrayCopy());
        }

        $this->trigger(self::EVENT_DOCUMENT_CREATE, $document);

        return $document;
    }

    /**
     * Create Documents in a batch
     *
     * @param string $collection
     * @param array<Document> $documents
     * @param int $batchSize
     * @param (callable(Document): void)|null $onNext
     * @param (callable(Throwable): void)|null $onError
     * @return int
     * @throws AuthorizationException
     * @throws StructureException
     * @throws \Throwable
     * @throws Exception
     */
    public function createDocuments(
        string $collection,
        array $documents,
        int $batchSize = self::INSERT_BATCH_SIZE,
        ?callable $onNext = null,
        ?callable $onError = null,
    ): int {
        if (!$this->adapter->getSharedTables() && $this->adapter->getTenantPerDocument()) {
            throw new DatabaseException('Shared tables must be enabled if tenant per document is enabled.');
        }

        if (empty($documents)) {
            return 0;
        }

        $batchSize = \min(Database::INSERT_BATCH_SIZE, \max(1, $batchSize));
        $collection = $this->silent(fn () => $this->getCollection($collection));
        if ($collection->getId() !== self::METADATA) {
            if (!$this->authorization->isValid(new Input(self::PERMISSION_CREATE, $collection->getCreate()))) {
                throw new AuthorizationException($this->authorization->getDescription());
            }
        }

        $time = DateTime::now();
        $modified = 0;
        $hasRelationships = !empty(\array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute['type'] === self::VAR_RELATIONSHIP
        ));

        foreach ($documents as $document) {
            $createdAt = $document->getCreatedAt();
            $updatedAt = $document->getUpdatedAt();

            $document
                ->setAttribute('$id', empty($document->getId()) ? ID::unique() : $document->getId())
                ->setAttribute('$collection', $collection->getId())
                ->setAttribute('$createdAt', ($createdAt === null || !$this->preserveDates) ? $time : $createdAt)
                ->setAttribute('$updatedAt', ($updatedAt === null || !$this->preserveDates) ? $time : $updatedAt);

            if (empty($document->getPermissions())) {
                $document->setAttribute('$permissions', []);
            }

            if ($this->adapter->getSharedTables()) {
                if ($this->adapter->getTenantPerDocument()) {
                    if ($document->getTenant() === null) {
                        throw new DatabaseException('Missing tenant. Tenant must be set when tenant per document is enabled.');
                    }
                } else {
                    $document->setAttribute('$tenant', $this->adapter->getTenant());
                }
            }

            $document = $this->encode($collection, $document);

            if ($this->validate) {
                $validator = new Structure(
                    $collection,
                    $this->adapter->getIdAttributeType(),
                    $this->adapter->getMinDateTime(),
                    $this->adapter->getMaxDateTime(),
                    $this->adapter->getSupportForAttributes(),
                    supportUnsignedBigInt: $this->adapter->getSupportForUnsignedBigInt(),
                    currentDocument: null
                );
                if (!$validator->isValid($document)) {
                    throw new StructureException($validator->getDescription());
                }
            }

            if ($this->resolveRelationships) {
                $document = $this->silent(fn () => $this->createDocumentRelationships($collection, $document));
            }

            $document = $this->adapter->castingBefore($collection, $document);
        }

        foreach (\array_chunk($documents, $batchSize) as $chunk) {
            $insert = fn () => $this->withTransaction(fn () => $this->adapter->createDocuments($collection, $chunk));
            // Set adapter flag before withTransaction so Mongo can opt out of a real txn.
            $batch = $this->skipDuplicates
                ? $this->adapter->skipDuplicates($insert)
                : $insert();

            // A SELECT per batch, read only by relationship population and by whatever the
            // caller does with the documents $onNext hands it. Skip it when neither applies.
            if ($onNext !== null || $hasRelationships) {
                $batch = $this->adapter->getSequences($collection->getId(), $batch);
            }

            if (!$this->inBatchRelationshipPopulation && $this->resolveRelationships) {
                $batch = $this->silent(fn () => $this->populateDocumentsRelationships($batch, $collection, $this->relationshipFetchDepth));
            }

            foreach ($batch as $document) {
                $document = $this->adapter->castingAfter($collection, $document);
                $document = $this->casting($collection, $document);
                $document = $this->decode($collection, $document);

                // Clear any negative-cache entry recorded before this insert.
                $this->withDocumentTenant($document, fn () => $this->purgeCachedDocumentInternal($collection->getId(), $document->getId()));

                try {
                    $onNext && $onNext($document);
                } catch (\Throwable $e) {
                    $onError ? $onError($e) : throw $e;
                }

                $modified++;
            }
        }

        $this->trigger(self::EVENT_DOCUMENTS_CREATE, new Document([
            '$collection' => $collection->getId(),
            'modified' => $modified
        ]));

        return $modified;
    }

    /**
     * @param Document $collection
     * @param Document $document
     * @return Document
     * @throws DatabaseException
     */
    private function createDocumentRelationships(Document $collection, Document $document): Document
    {
        $attributes = $collection->getAttribute('attributes', []);

        $relationships = \array_filter(
            $attributes,
            fn ($attribute) => $attribute['type'] === Database::VAR_RELATIONSHIP
        );

        $stackCount = count($this->relationshipWriteStack);

        foreach ($relationships as $relationship) {
            $key = $relationship['key'];
            $value = $document->getAttribute($key);
            $relatedCollection = $this->getCollection($relationship['options']['relatedCollection']);
            $relationType = $relationship['options']['relationType'];
            $twoWay = $relationship['options']['twoWay'];
            $twoWayKey = $relationship['options']['twoWayKey'];
            $side = $relationship['options']['side'];

            if ($stackCount >= Database::RELATION_MAX_DEPTH - 1 && $this->relationshipWriteStack[$stackCount - 1] !== $relatedCollection->getId()) {
                $document->removeAttribute($key);

                continue;
            }

            $this->relationshipWriteStack[] = $collection->getId();

            try {
                switch (\gettype($value)) {
                    case 'array':
                        if ($relationType === Database::RELATION_ONE_TO_ONE && !$twoWay && $side === Database::RELATION_SIDE_CHILD) {
                            throw new RelationshipException('Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.');
                        }

                        if (
                            ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_PARENT) ||
                            ($relationType === Database::RELATION_ONE_TO_MANY && $side === Database::RELATION_SIDE_CHILD) ||
                            ($relationType === Database::RELATION_ONE_TO_ONE)
                        ) {
                            throw new RelationshipException('Invalid relationship value. Must be either a document ID or a document, array given.');
                        }

                        // List of documents or IDs
                        foreach ($value as $related) {
                            switch (\gettype($related)) {
                                case 'object':
                                    if (!$related instanceof Document) {
                                        throw new RelationshipException('Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.');
                                    }
                                    $this->relateDocuments(
                                        $collection,
                                        $relatedCollection,
                                        $key,
                                        $document,
                                        $related,
                                        $relationType,
                                        $twoWay,
                                        $twoWayKey,
                                        $side,
                                    );
                                    break;
                                case 'string':
                                    $this->relateDocumentsById(
                                        $collection,
                                        $relatedCollection,
                                        $key,
                                        $document->getId(),
                                        $related,
                                        $relationType,
                                        $twoWay,
                                        $twoWayKey,
                                        $side,
                                    );
                                    break;
                                default:
                                    throw new RelationshipException('Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.');
                            }
                        }
                        $document->removeAttribute($key);
                        break;

                    case 'object':
                        if (!$value instanceof Document) {
                            throw new RelationshipException('Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.');
                        }

                        if ($relationType === Database::RELATION_ONE_TO_ONE && !$twoWay && $side === Database::RELATION_SIDE_CHILD) {
                            throw new RelationshipException('Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.');
                        }

                        if (
                            ($relationType === Database::RELATION_ONE_TO_MANY && $side === Database::RELATION_SIDE_PARENT) ||
                            ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_CHILD) ||
                            ($relationType === Database::RELATION_MANY_TO_MANY)
                        ) {
                            throw new RelationshipException('Invalid relationship value. Must be either an array of documents or document IDs, document given.');
                        }

                        $relatedId = $this->relateDocuments(
                            $collection,
                            $relatedCollection,
                            $key,
                            $document,
                            $value,
                            $relationType,
                            $twoWay,
                            $twoWayKey,
                            $side,
                        );
                        $document->setAttribute($key, $relatedId);
                        break;

                    case 'string':
                        if ($relationType === Database::RELATION_ONE_TO_ONE && $twoWay === false && $side === Database::RELATION_SIDE_CHILD) {
                            throw new RelationshipException('Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.');
                        }

                        if (
                            ($relationType === Database::RELATION_ONE_TO_MANY && $side === Database::RELATION_SIDE_PARENT) ||
                            ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_CHILD) ||
                            ($relationType === Database::RELATION_MANY_TO_MANY)
                        ) {
                            throw new RelationshipException('Invalid relationship value. Must be either an array of documents or document IDs, document ID given.');
                        }

                        // Single document ID
                        $this->relateDocumentsById(
                            $collection,
                            $relatedCollection,
                            $key,
                            $document->getId(),
                            $value,
                            $relationType,
                            $twoWay,
                            $twoWayKey,
                            $side,
                        );
                        break;

                    case 'NULL':
                        // TODO: This might need to depend on the relation type, to be either set to null or removed?

                        if (
                            ($relationType === Database::RELATION_ONE_TO_MANY && $side === Database::RELATION_SIDE_CHILD) ||
                            ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_PARENT) ||
                            ($relationType === Database::RELATION_ONE_TO_ONE && $side === Database::RELATION_SIDE_PARENT) ||
                            ($relationType === Database::RELATION_ONE_TO_ONE && $side === Database::RELATION_SIDE_CHILD && $twoWay === true)
                        ) {
                            break;
                        }

                        $document->removeAttribute($key);
                        // No related document
                        break;

                    default:
                        throw new RelationshipException('Invalid relationship value. Must be either a document, document ID, or an array of documents or document IDs.');
                }
            } finally {
                \array_pop($this->relationshipWriteStack);
            }
        }

        return $document;
    }

    /**
     * @param Document $collection
     * @param Document $relatedCollection
     * @param string $key
     * @param Document $document
     * @param Document $relation
     * @param string $relationType
     * @param bool $twoWay
     * @param string $twoWayKey
     * @param string $side
     * @return string related document ID
     *
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws StructureException
     * @throws Exception
     */
    private function relateDocuments(
        Document $collection,
        Document $relatedCollection,
        string $key,
        Document $document,
        Document $relation,
        string $relationType,
        bool $twoWay,
        string $twoWayKey,
        string $side,
    ): string {
        switch ($relationType) {
            case Database::RELATION_ONE_TO_ONE:
                if ($twoWay) {
                    $relation->setAttribute($twoWayKey, $document->getId());
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $relation->setAttribute($twoWayKey, $document->getId());
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_CHILD) {
                    $relation->setAttribute($twoWayKey, $document->getId());
                }
                break;
        }

        // Try to get the related document
        $related = $this->getDocument($relatedCollection->getId(), $relation->getId());

        if ($related->isEmpty()) {
            // If the related document doesn't exist, create it, inheriting permissions if none are set
            if (!isset($relation['$permissions'])) {
                $relation->setAttribute('$permissions', $document->getPermissions());
            }

            $related = $this->createDocument($relatedCollection->getId(), $relation);
        } elseif ($related->getAttributes() != $relation->getAttributes()) {
            // If the related document exists and the data is not the same, update it
            foreach ($relation->getAttributes() as $attribute => $value) {
                $related->setAttribute($attribute, $value);
            }

            $related = $this->updateDocument($relatedCollection->getId(), $related->getId(), $related);
        }

        if ($relationType === Database::RELATION_MANY_TO_MANY) {
            $junction = $this->getJunctionCollection($collection, $relatedCollection, $side);

            $this->createDocument($junction, new Document([
                $key => $related->getId(),
                $twoWayKey => $document->getId(),
                '$permissions' => [
                    Permission::read(Role::any()),
                    Permission::update(Role::any()),
                    Permission::delete(Role::any()),
                ]
            ]));
        }

        return $related->getId();
    }

    /**
     * @param Document $collection
     * @param Document $relatedCollection
     * @param string $key
     * @param string $documentId
     * @param string $relationId
     * @param string $relationType
     * @param bool $twoWay
     * @param string $twoWayKey
     * @param string $side
     * @return void
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws StructureException
     * @throws Exception
     */
    private function relateDocumentsById(
        Document $collection,
        Document $relatedCollection,
        string $key,
        string $documentId,
        string $relationId,
        string $relationType,
        bool $twoWay,
        string $twoWayKey,
        string $side,
    ): void {
        // Get the related document, will be empty on permissions failure
        $related = $this->skipRelationships(fn () => $this->getDocument($relatedCollection->getId(), $relationId));

        if ($related->isEmpty() && $this->checkRelationshipsExist) {
            return;
        }

        switch ($relationType) {
            case Database::RELATION_ONE_TO_ONE:
                if ($twoWay) {
                    $related->setAttribute($twoWayKey, $documentId);
                    $this->skipRelationships(fn () => $this->updateDocument($relatedCollection->getId(), $relationId, $related));
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $related->setAttribute($twoWayKey, $documentId);
                    $this->skipRelationships(fn () => $this->updateDocument($relatedCollection->getId(), $relationId, $related));
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_CHILD) {
                    $related->setAttribute($twoWayKey, $documentId);
                    $this->skipRelationships(fn () => $this->updateDocument($relatedCollection->getId(), $relationId, $related));
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $this->purgeCachedDocument($relatedCollection->getId(), $relationId);

                $junction = $this->getJunctionCollection($collection, $relatedCollection, $side);

                $this->skipRelationships(fn () => $this->createDocument($junction, new Document([
                    $key => $relationId,
                    $twoWayKey => $documentId,
                    '$permissions' => [
                        Permission::read(Role::any()),
                        Permission::update(Role::any()),
                        Permission::delete(Role::any()),
                    ]
                ])));
                break;
        }
    }

    /**
     * Update Document
     *
     * @param string $collection
     * @param string $id
     * @param Document $document
     * @return Document
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws StructureException
     */
    public function updateDocument(string $collection, string $id, Document $document): Document
    {
        if (!$id) {
            throw new DatabaseException('Must define $id attribute');
        }

        $collection = $this->silent(fn () => $this->getCollection($collection));
        $newUpdatedAt = $document->getUpdatedAt();
        $hasOperators = false;
        $document = $this->withTransaction(function () use ($collection, $id, $document, $newUpdatedAt, &$hasOperators) {
            $time = DateTime::now();
            $old = $this->authorization->skip(fn () => $this->silent(
                fn () => $this->getDocument($collection->getId(), $id, forUpdate: true)
            ));
            if ($old->isEmpty()) {
                return new Document();
            }

            $skipPermissionsUpdate = true;

            if ($document->offsetExists('$permissions')) {
                $originalPermissions = $old->getPermissions();
                $currentPermissions = $document->getPermissions();

                sort($originalPermissions);
                sort($currentPermissions);

                $skipPermissionsUpdate = ($originalPermissions === $currentPermissions);
            }

            // UID change
            if ($document->offsetExists('$id') && $document->getId() !== $id) {
                $skipPermissionsUpdate = false;
            }

            $createdAt = $document->getCreatedAt();

            $document = \array_merge($old->getArrayCopy(), $document->getArrayCopy());
            $document['$collection'] = $old->getAttribute('$collection'); // Make sure user doesn't switch collection ID
            $document['$sequence'] = $old->getSequence(); // Sequence is immutable
            $document['$createdAt'] = ($createdAt === null || !$this->preserveDates) ? $old->getCreatedAt() : $createdAt;

            if ($this->adapter->getSharedTables()) {
                $tenant = $old->getTenant();
                $document['$tenant'] = $tenant;
                $old->setAttribute('$tenant', $tenant); // Normalize for strict comparison
            }
            $document = new Document($document);

            // Ahead of change detection: a dropped attribute is never persisted, so
            // counting it as a change would bump $updatedAt and fire an update event
            // for a write that leaves the stored document identical.
            $document = $this->removeUnknownAttributes($collection, $document);

            $attributes = $collection->getAttribute('attributes', []);

            $relationships = \array_filter($attributes, function ($attribute) {
                return $attribute['type'] === Database::VAR_RELATIONSHIP;
            });

            $shouldUpdate = false;

            if ($collection->getId() !== self::METADATA) {
                $documentSecurity = $collection->getAttribute('documentSecurity', false);

                foreach ($relationships as $relationship) {
                    $relationships[$relationship->getAttribute('key')] = $relationship;
                }

                foreach ($document as $key => $value) {
                    if (Operator::isOperator($value)) {
                        $shouldUpdate = true;
                        break;
                    }
                }

                // Compare if the document has any changes
                foreach ($document as $key => $value) {
                    if (\array_key_exists($key, $relationships)) {
                        if (\count($this->relationshipWriteStack) >= Database::RELATION_MAX_DEPTH - 1) {
                            continue;
                        }

                        $relationType = (string)$relationships[$key]['options']['relationType'];
                        $side = (string)$relationships[$key]['options']['side'];
                        switch ($relationType) {
                            case Database::RELATION_ONE_TO_ONE:
                                $oldValue = $old->getAttribute($key) instanceof Document
                                    ? $old->getAttribute($key)->getId()
                                    : $old->getAttribute($key);

                                if ((\is_null($value) !== \is_null($oldValue))
                                    || (\is_string($value) && $value !== $oldValue)
                                    || ($value instanceof Document && $value->getId() !== $oldValue)
                                ) {
                                    $shouldUpdate = true;
                                }
                                break;
                            case Database::RELATION_ONE_TO_MANY:
                            case Database::RELATION_MANY_TO_ONE:
                            case Database::RELATION_MANY_TO_MANY:
                                if (
                                    ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_PARENT) ||
                                    ($relationType === Database::RELATION_ONE_TO_MANY && $side === Database::RELATION_SIDE_CHILD)
                                ) {
                                    $oldValue = $old->getAttribute($key) instanceof Document
                                        ? $old->getAttribute($key)->getId()
                                        : $old->getAttribute($key);

                                    if ((\is_null($value) !== \is_null($oldValue))
                                        || (\is_string($value) && $value !== $oldValue)
                                        || ($value instanceof Document && $value->getId() !== $oldValue)
                                    ) {
                                        $shouldUpdate = true;
                                    }
                                    break;
                                }

                                if (Operator::isOperator($value)) {
                                    $shouldUpdate = true;
                                    break;
                                }

                                if (!\is_array($value) || !\array_is_list($value)) {
                                    throw new RelationshipException('Invalid relationship value. Must be either an array of documents or document IDs, ' . \gettype($value) . ' given.');
                                }

                                if (\count($old->getAttribute($key)) !== \count($value)) {
                                    $shouldUpdate = true;
                                    break;
                                }

                                foreach ($value as $index => $relation) {
                                    $oldValue = $old->getAttribute($key)[$index] instanceof Document
                                        ? $old->getAttribute($key)[$index]->getId()
                                        : $old->getAttribute($key)[$index];

                                    if (
                                        (\is_string($relation) && $relation !== $oldValue) ||
                                        ($relation instanceof Document && $relation->getId() !== $oldValue)
                                    ) {
                                        $shouldUpdate = true;
                                        break;
                                    }
                                }
                                break;
                        }

                        if ($shouldUpdate) {
                            break;
                        }

                        continue;
                    }

                    $oldValue = $old->getAttribute($key);

                    if (!self::valuesEqual($value, $oldValue)) {
                        $shouldUpdate = true;
                        break;
                    }
                }

                $updatePermissions = [
                    ...$collection->getUpdate(),
                    ...($documentSecurity ? $old->getUpdate() : [])
                ];

                $readPermissions = [
                    ...$collection->getRead(),
                    ...($documentSecurity ? $old->getRead() : [])
                ];

                if ($shouldUpdate) {
                    if (!$this->authorization->isValid(new Input(self::PERMISSION_UPDATE, $updatePermissions))) {
                        throw new AuthorizationException($this->authorization->getDescription());
                    }
                } else {
                    if (!$this->authorization->isValid(new Input(self::PERMISSION_READ, $readPermissions))) {
                        throw new AuthorizationException($this->authorization->getDescription());
                    }
                }
            }

            if ($shouldUpdate) {
                $document->setAttribute('$updatedAt', ($newUpdatedAt === null || !$this->preserveDates) ? $time : $newUpdatedAt);
            }

            // Check if document was updated after the request timestamp
            $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
            if (!is_null($this->timestamp) && $oldUpdatedAt > $this->timestamp) {
                throw new ConflictException('Document was updated after the request timestamp');
            }

            $document = $this->encode($collection, $document);

            if ($this->validate) {
                $structureValidator = new Structure(
                    $collection,
                    $this->adapter->getIdAttributeType(),
                    $this->adapter->getMinDateTime(),
                    $this->adapter->getMaxDateTime(),
                    $this->adapter->getSupportForAttributes(),
                    supportUnsignedBigInt: $this->adapter->getSupportForUnsignedBigInt(),
                    currentDocument: $old
                );
                if (!$structureValidator->isValid($document)) { // Make sure updated structure still apply collection rules (if any)
                    throw new StructureException($structureValidator->getDescription());
                }
            }

            if ($this->resolveRelationships) {
                $document = $this->silent(fn () => $this->updateDocumentRelationships($collection, $old, $document));
            }

            $document = $this->adapter->castingBefore($collection, $document);

            $this->adapter->updateDocument($collection, $id, $document, $skipPermissionsUpdate);

            $document = $this->adapter->castingAfter($collection, $document);

            $this->purgeCachedDocument($collection->getId(), $id);

            if ($document->getId() !== $id) {
                $this->purgeCachedDocument($collection->getId(), $document->getId());
            }

            // If operators were used, refetch inside the transaction so the returned value reflects
            // this operation's own write (read-your-writes). Refetching after commit could observe a
            // concurrent update and return that value instead of the result of this operation.
            foreach ($document->getArrayCopy() as $value) {
                if (Operator::isOperator($value)) {
                    $hasOperators = true;
                    break;
                }
            }

            if ($hasOperators) {
                $refetched = $this->refetchDocuments($collection, [$document]);
                $document = $refetched[0];
            }

            return $document;
        });

        if ($document->isEmpty()) {
            return $document;
        }

        // Purge again after commit so readers cannot re-cache the pre-commit version
        $this->purgeCachedDocumentInternal($collection->getId(), $id);

        if (!$this->inBatchRelationshipPopulation && $this->resolveRelationships) {
            $documents = $this->silent(fn () => $this->populateDocumentsRelationships([$document], $collection, $this->relationshipFetchDepth));
            $document = $documents[0];
        }

        // The operator refetch already returns a decoded document (via find()); decoding again
        // would double-apply the decode filters.
        if (!$hasOperators) {
            $document = $this->decode($collection, $document);
        }

        // Convert to custom document type if mapped
        if (isset($this->documentTypes[$collection->getId()])) {
            $document = $this->createDocumentInstance($collection->getId(), $document->getArrayCopy());
        }

        $this->trigger(self::EVENT_DOCUMENT_UPDATE, $document);

        return $document;
    }

    /**
     * Update documents
     *
     * Updates all documents which match the given query.
     *
     * @param string $collection
     * @param Document $updates
     * @param array<Query> $queries
     * @param int $batchSize
     * @param (callable(Document $updated, Document $old): void)|null $onNext
     * @param (callable(Throwable): void)|null $onError
     * @return int
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DuplicateException
     * @throws QueryException
     * @throws StructureException
     * @throws TimeoutException
     * @throws \Throwable
     * @throws Exception
     */
    public function updateDocuments(
        string $collection,
        Document $updates,
        array $queries = [],
        int $batchSize = self::INSERT_BATCH_SIZE,
        ?callable $onNext = null,
        ?callable $onError = null,
    ): int {
        if ($updates->isEmpty()) {
            return 0;
        }

        $batchSize = \min(Database::INSERT_BATCH_SIZE, \max(1, $batchSize));
        $collection = $this->silent(fn () => $this->getCollection($collection));
        if ($collection->isEmpty()) {
            throw new DatabaseException('Collection not found');
        }

        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $skipAuth = $this->authorization->isValid(new Input(self::PERMISSION_UPDATE, $collection->getUpdate()));

        if (!$skipAuth && !$documentSecurity && $collection->getId() !== self::METADATA) {
            throw new AuthorizationException($this->authorization->getDescription());
        }

        $attributes = $collection->getAttribute('attributes', []);
        $indexes = $collection->getAttribute('indexes', []);

        $this->checkQueryTypes($queries);

        if ($this->validate) {
            $validator = new DocumentsValidator(
                $attributes,
                $indexes,
                $this->adapter->getIdAttributeType(),
                $this->maxQueryValues,
                $this->adapter->getMaxUIDLength(),
                $this->adapter->getMinDateTime(),
                $this->adapter->getMaxDateTime(),
                $this->adapter->getSupportForAttributes(),
                $this->adapter->getSupportForUnsignedBigInt()
            );

            if (!$validator->isValid($queries)) {
                throw new QueryException($validator->getDescription());
            }
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'];
        $cursor = $grouped['cursor'];

        if (!empty($cursor) && $cursor->getCollection() !== $collection->getId()) {
            throw new DatabaseException("Cursor document must be from the same Collection.");
        }

        unset($updates['$id']);
        unset($updates['$tenant']);

        if (($updates->getCreatedAt() === null || !$this->preserveDates)) {
            unset($updates['$createdAt']);
        } else {
            $updates['$createdAt'] = $updates->getCreatedAt();
        }

        if ($this->adapter->getSharedTables()) {
            $updates['$tenant'] = $this->adapter->getTenant();
        }

        $updatedAt = $updates->getUpdatedAt();
        $updates['$updatedAt'] = ($updatedAt === null || !$this->preserveDates) ? DateTime::now() : $updatedAt;

        $updates = $this->encode(
            $collection,
            $updates,
            applyDefaults: false
        );

        if ($this->validate) {
            $validator = new PartialStructure(
                $collection,
                $this->adapter->getIdAttributeType(),
                $this->adapter->getMinDateTime(),
                $this->adapter->getMaxDateTime(),
                $this->adapter->getSupportForAttributes(),
                supportUnsignedBigInt: $this->adapter->getSupportForUnsignedBigInt(),
                currentDocument: null // No old document available in bulk updates
            );

            if (!$validator->isValid($updates)) {
                throw new StructureException($validator->getDescription());
            }
        }

        $originalLimit = $limit;
        $last = $cursor;
        $modified = 0;

        while (true) {
            if ($limit && $limit < $batchSize) {
                $batchSize = $limit;
            } elseif (!empty($limit)) {
                $limit -= $batchSize;
            }

            $new = [
                Query::limit($batchSize)
            ];

            if (!empty($last)) {
                $new[] = Query::cursorAfter($last);
            }

            $batch = $this->silent(fn () => $this->find(
                $collection->getId(),
                array_merge($new, $queries),
                forPermission: Database::PERMISSION_UPDATE
            ));

            if (empty($batch)) {
                break;
            }

            $old = array_map(fn ($doc) => clone $doc, $batch);
            $currentPermissions = $updates->getPermissions();
            sort($currentPermissions);

            $this->withTransaction(function () use ($collection, $updates, &$batch, $currentPermissions) {
                foreach ($batch as $index => $document) {
                    $skipPermissionsUpdate = true;

                    if ($updates->offsetExists('$permissions')) {
                        if (!$document->offsetExists('$permissions')) {
                            throw new QueryException('Permission document missing in select');
                        }

                        $originalPermissions = $document->getPermissions();

                        \sort($originalPermissions);

                        $skipPermissionsUpdate = ($originalPermissions === $currentPermissions);
                    }

                    $document->setAttribute('$skipPermissionsUpdate', $skipPermissionsUpdate);

                    $new = new Document(\array_merge($document->getArrayCopy(), $updates->getArrayCopy()));

                    if ($this->resolveRelationships) {
                        $this->silent(fn () => $this->updateDocumentRelationships($collection, $document, $new));
                    }

                    $document = $new;

                    // Check if document was updated after the request timestamp
                    try {
                        $oldUpdatedAt = new \DateTime($document->getUpdatedAt());
                    } catch (Exception $e) {
                        throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
                    }

                    if (!is_null($this->timestamp) && $oldUpdatedAt > $this->timestamp) {
                        throw new ConflictException('Document was updated after the request timestamp');
                    }
                    $encoded = $this->encode($collection, $document);
                    $batch[$index] = $this->adapter->castingBefore($collection, $encoded);
                }

                $this->adapter->updateDocuments(
                    $collection,
                    $updates,
                    $batch
                );
            });

            $updates = $this->adapter->castingBefore($collection, $updates);

            $hasOperators = false;
            foreach ($updates->getArrayCopy() as $value) {
                if (Operator::isOperator($value)) {
                    $hasOperators = true;
                    break;
                }
            }

            if ($hasOperators) {
                $batch = $this->refetchDocuments($collection, $batch, $grouped['selections']);
            }

            foreach ($batch as $index => $doc) {
                $doc = $this->adapter->castingAfter($collection, $doc);
                $doc->removeAttribute('$skipPermissionsUpdate');
                $this->purgeCachedDocument($collection->getId(), $doc->getId());
                // The operator refetch goes through find(), which already returns fully decoded
                // documents. Decoding again would double-apply the decode filters (and, because
                // this call passes no selections, re-materialize non-selected attributes).
                if (!$hasOperators) {
                    $doc = $this->decode($collection, $doc);
                }
                try {
                    $onNext && $onNext($doc, $old[$index]);
                } catch (Throwable $th) {
                    $onError ? $onError($th) : throw $th;
                }
                $modified++;
            }

            if (count($batch) < $batchSize) {
                break;
            } elseif ($originalLimit && $modified == $originalLimit) {
                break;
            }

            $last = \end($batch);
        }

        $this->trigger(self::EVENT_DOCUMENTS_UPDATE, new Document([
            '$collection' => $collection->getId(),
            'modified' => $modified
        ]));

        return $modified;
    }

    /**
     * @param Document $collection
     * @param Document $old
     * @param Document $document
     *
     * @return Document
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws DuplicateException
     * @throws StructureException
     */
    private function updateDocumentRelationships(Document $collection, Document $old, Document $document): Document
    {
        $attributes = $collection->getAttribute('attributes', []);

        $relationships = \array_filter($attributes, function ($attribute) {
            return $attribute['type'] === Database::VAR_RELATIONSHIP;
        });

        $stackCount = count($this->relationshipWriteStack);

        foreach ($relationships as $index => $relationship) {
            /** @var string $key */
            $key = $relationship['key'];
            $value = $document->getAttribute($key);
            $oldValue = $old->getAttribute($key);
            $relatedCollection = $this->getCollection($relationship['options']['relatedCollection']);
            $relationType = (string)$relationship['options']['relationType'];
            $twoWay = (bool)$relationship['options']['twoWay'];
            $twoWayKey = (string)$relationship['options']['twoWayKey'];
            $side = (string)$relationship['options']['side'];

            if (Operator::isOperator($value)) {
                $operator = $value;
                if ($operator->isArrayOperation()) {
                    $existingIds = [];
                    if (\is_array($oldValue)) {
                        $existingIds = \array_map(function ($item) {
                            if ($item instanceof Document) {
                                return $item->getId();
                            }
                            return $item;
                        }, $oldValue);
                    }

                    $value = $this->applyRelationshipOperator($operator, $existingIds);
                    $document->setAttribute($key, $value);
                }
            }

            if ($oldValue == $value) {
                if (
                    ($relationType === Database::RELATION_ONE_TO_ONE
                        || ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_PARENT)) &&
                    $value instanceof Document
                ) {
                    $document->setAttribute($key, $value->getId());
                    continue;
                }
                $document->removeAttribute($key);
                continue;
            }

            if ($stackCount >= Database::RELATION_MAX_DEPTH - 1 && $this->relationshipWriteStack[$stackCount - 1] !== $relatedCollection->getId()) {
                $document->removeAttribute($key);
                continue;
            }

            $this->relationshipWriteStack[] = $collection->getId();

            try {
                switch ($relationType) {
                    case Database::RELATION_ONE_TO_ONE:
                        if (!$twoWay) {
                            if ($side === Database::RELATION_SIDE_CHILD) {
                                throw new RelationshipException('Invalid relationship value. Cannot set a value from the child side of a oneToOne relationship when twoWay is false.');
                            }

                            if (\is_string($value)) {
                                $related = $this->skipRelationships(fn () => $this->getDocument($relatedCollection->getId(), $value, [Query::select(['$id'])]));
                                if ($related->isEmpty()) {
                                    // If no such document exists in related collection
                                    // For one-one we need to update the related key to null if no relation exists
                                    $document->setAttribute($key, null);
                                }
                            } elseif ($value instanceof Document) {
                                $relationId = $this->relateDocuments(
                                    $collection,
                                    $relatedCollection,
                                    $key,
                                    $document,
                                    $value,
                                    $relationType,
                                    false,
                                    $twoWayKey,
                                    $side,
                                );
                                $document->setAttribute($key, $relationId);
                            } elseif (is_array($value)) {
                                throw new RelationshipException('Invalid relationship value. Must be either a document, document ID or null. Array given.');
                            }

                            break;
                        }

                        switch (\gettype($value)) {
                            case 'string':
                                $related = $this->skipRelationships(
                                    fn () => $this->getDocument($relatedCollection->getId(), $value, [Query::select(['$id'])])
                                );

                                if ($related->isEmpty()) {
                                    // If no such document exists in related collection
                                    // For one-one we need to update the related key to null if no relation exists
                                    $document->setAttribute($key, null);
                                    break;
                                }
                                if (
                                    $oldValue?->getId() !== $value
                                    && !($this->skipRelationships(fn () => $this->findOne($relatedCollection->getId(), [
                                        Query::select(['$id']),
                                        Query::equal($twoWayKey, [$value]),
                                    ]))->isEmpty())
                                ) {
                                    // Have to do this here because otherwise relations would be updated before the database can throw the unique violation
                                    throw new DuplicateException('Document already has a related document');
                                }

                                $this->skipRelationships(fn () => $this->updateDocument(
                                    $relatedCollection->getId(),
                                    $related->getId(),
                                    $related->setAttribute($twoWayKey, $document->getId())
                                ));
                                break;
                            case 'object':
                                if ($value instanceof Document) {
                                    $related = $this->skipRelationships(fn () => $this->getDocument($relatedCollection->getId(), $value->getId()));

                                    if (
                                        $oldValue?->getId() !== $value->getId()
                                        && !($this->skipRelationships(fn () => $this->findOne($relatedCollection->getId(), [
                                            Query::select(['$id']),
                                            Query::equal($twoWayKey, [$value->getId()]),
                                        ]))->isEmpty())
                                    ) {
                                        // Have to do this here because otherwise relations would be updated before the database can throw the unique violation
                                        throw new DuplicateException('Document already has a related document');
                                    }

                                    $this->relationshipWriteStack[] = $relatedCollection->getId();
                                    if ($related->isEmpty()) {
                                        if (!isset($value['$permissions'])) {
                                            $value->setAttribute('$permissions', $document->getAttribute('$permissions'));
                                        }
                                        $related = $this->createDocument(
                                            $relatedCollection->getId(),
                                            $value->setAttribute($twoWayKey, $document->getId())
                                        );
                                    } else {
                                        $related = $this->updateDocument(
                                            $relatedCollection->getId(),
                                            $related->getId(),
                                            $value->setAttribute($twoWayKey, $document->getId())
                                        );
                                    }
                                    \array_pop($this->relationshipWriteStack);

                                    $document->setAttribute($key, $related->getId());
                                    break;
                                }
                                // no break
                            case 'NULL':
                                if (!\is_null($oldValue?->getId())) {
                                    $oldRelated = $this->skipRelationships(
                                        fn () => $this->getDocument($relatedCollection->getId(), $oldValue->getId())
                                    );
                                    $this->skipRelationships(fn () => $this->updateDocument(
                                        $relatedCollection->getId(),
                                        $oldRelated->getId(),
                                        new Document([$twoWayKey => null])
                                    ));
                                }
                                break;
                            default:
                                throw new RelationshipException('Invalid relationship value. Must be either a document, document ID or null.');
                        }
                        break;
                    case Database::RELATION_ONE_TO_MANY:
                    case Database::RELATION_MANY_TO_ONE:
                        if (
                            ($relationType === Database::RELATION_ONE_TO_MANY && $side === Database::RELATION_SIDE_PARENT) ||
                            ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_CHILD)
                        ) {
                            if (!\is_array($value) || !\array_is_list($value)) {
                                throw new RelationshipException('Invalid relationship value. Must be either an array of documents or document IDs, ' . \gettype($value) . ' given.');
                            }

                            $oldIds = \array_map(fn ($document) => $document->getId(), $oldValue);

                            $newIds = \array_map(function ($item) {
                                if (\is_string($item)) {
                                    return $item;
                                } elseif ($item instanceof Document) {
                                    return $item->getId();
                                } else {
                                    throw new RelationshipException('Invalid relationship value. Must be either a document or document ID.');
                                }
                            }, $value);

                            $removedDocuments = \array_diff($oldIds, $newIds);

                            foreach ($removedDocuments as $relation) {
                                $this->authorization->skip(fn () => $this->skipRelationships(fn () => $this->updateDocument(
                                    $relatedCollection->getId(),
                                    $relation,
                                    new Document([$twoWayKey => null])
                                )));
                            }

                            foreach ($value as $relation) {
                                if (\is_string($relation)) {
                                    $related = $this->skipRelationships(
                                        fn () => $this->getDocument($relatedCollection->getId(), $relation, [Query::select(['$id'])])
                                    );

                                    if ($related->isEmpty()) {
                                        continue;
                                    }

                                    $this->skipRelationships(fn () => $this->updateDocument(
                                        $relatedCollection->getId(),
                                        $related->getId(),
                                        $related->setAttribute($twoWayKey, $document->getId())
                                    ));
                                } elseif ($relation instanceof Document) {
                                    $related = $this->skipRelationships(
                                        fn () => $this->getDocument($relatedCollection->getId(), $relation->getId(), [Query::select(['$id'])])
                                    );

                                    if ($related->isEmpty()) {
                                        if (!isset($relation['$permissions'])) {
                                            $relation->setAttribute('$permissions', $document->getAttribute('$permissions'));
                                        }
                                        $this->createDocument(
                                            $relatedCollection->getId(),
                                            $relation->setAttribute($twoWayKey, $document->getId())
                                        );
                                    } else {
                                        $this->updateDocument(
                                            $relatedCollection->getId(),
                                            $related->getId(),
                                            $relation->setAttribute($twoWayKey, $document->getId())
                                        );
                                    }
                                } else {
                                    throw new RelationshipException('Invalid relationship value.');
                                }
                            }

                            $document->removeAttribute($key);
                            break;
                        }

                        if (\is_string($value)) {
                            $related = $this->skipRelationships(
                                fn () => $this->getDocument($relatedCollection->getId(), $value, [Query::select(['$id'])])
                            );

                            if ($related->isEmpty()) {
                                // If no such document exists in related collection
                                // For many-one we need to update the related key to null if no relation exists
                                $document->setAttribute($key, null);
                            }
                            $this->purgeCachedDocument($relatedCollection->getId(), $value);
                        } elseif ($value instanceof Document) {
                            $related = $this->skipRelationships(
                                fn () => $this->getDocument($relatedCollection->getId(), $value->getId(), [Query::select(['$id'])])
                            );

                            if ($related->isEmpty()) {
                                if (!isset($value['$permissions'])) {
                                    $value->setAttribute('$permissions', $document->getAttribute('$permissions'));
                                }
                                $this->createDocument(
                                    $relatedCollection->getId(),
                                    $value
                                );
                            } elseif ($related->getAttributes() != $value->getAttributes()) {
                                $this->updateDocument(
                                    $relatedCollection->getId(),
                                    $related->getId(),
                                    $value
                                );
                                $this->purgeCachedDocument($relatedCollection->getId(), $related->getId());
                            }

                            $document->setAttribute($key, $value->getId());
                        } elseif (\is_null($value)) {
                            break;
                        } elseif (is_array($value)) {
                            throw new RelationshipException('Invalid relationship value. Must be either a document ID or a document, array given.');
                        } elseif (empty($value)) {
                            throw new RelationshipException('Invalid relationship value. Must be either a document ID or a document.');
                        } else {
                            throw new RelationshipException('Invalid relationship value.');
                        }

                        break;
                    case Database::RELATION_MANY_TO_MANY:
                        if (\is_null($value)) {
                            break;
                        }
                        if (!\is_array($value)) {
                            throw new RelationshipException('Invalid relationship value. Must be an array of documents or document IDs.');
                        }

                        $oldIds = \array_map(fn ($document) => $document->getId(), $oldValue);

                        $newIds = \array_map(function ($item) {
                            if (\is_string($item)) {
                                return $item;
                            } elseif ($item instanceof Document) {
                                return $item->getId();
                            } else {
                                throw new RelationshipException('Invalid relationship value. Must be either a document or document ID.');
                            }
                        }, $value);

                        $removedDocuments = \array_diff($oldIds, $newIds);

                        foreach ($removedDocuments as $relation) {
                            $junction = $this->getJunctionCollection($collection, $relatedCollection, $side);

                            $junctions = $this->find($junction, [
                                Query::equal($key, [$relation]),
                                Query::equal($twoWayKey, [$document->getId()]),
                                Query::limit(PHP_INT_MAX)
                            ]);

                            foreach ($junctions as $junction) {
                                $this->authorization->skip(fn () => $this->deleteDocument($junction->getCollection(), $junction->getId()));
                            }
                        }

                        foreach ($value as $relation) {
                            if (\is_string($relation)) {
                                if (\in_array($relation, $oldIds) || $this->getDocument($relatedCollection->getId(), $relation, [Query::select(['$id'])])->isEmpty()) {
                                    continue;
                                }
                            } elseif ($relation instanceof Document) {
                                $related = $this->getDocument($relatedCollection->getId(), $relation->getId(), [Query::select(['$id'])]);

                                if ($related->isEmpty()) {
                                    if (!isset($value['$permissions'])) {
                                        $relation->setAttribute('$permissions', $document->getAttribute('$permissions'));
                                    }
                                    $related = $this->createDocument(
                                        $relatedCollection->getId(),
                                        $relation
                                    );
                                } elseif ($related->getAttributes() != $relation->getAttributes()) {
                                    $related = $this->updateDocument(
                                        $relatedCollection->getId(),
                                        $related->getId(),
                                        $relation
                                    );
                                }

                                if (\in_array($relation->getId(), $oldIds)) {
                                    continue;
                                }

                                $relation = $related->getId();
                            } else {
                                throw new RelationshipException('Invalid relationship value. Must be either a document or document ID.');
                            }

                            $this->skipRelationships(fn () => $this->createDocument(
                                $this->getJunctionCollection($collection, $relatedCollection, $side),
                                new Document([
                                    $key => $relation,
                                    $twoWayKey => $document->getId(),
                                    '$permissions' => [
                                        Permission::read(Role::any()),
                                        Permission::update(Role::any()),
                                        Permission::delete(Role::any()),
                                    ],
                                ])
                            ));
                        }

                        $document->removeAttribute($key);
                        break;
                }
            } finally {
                \array_pop($this->relationshipWriteStack);
            }
        }

        return $document;
    }

    private function getJunctionCollection(Document $collection, Document $relatedCollection, string $side): string
    {
        return $side === Database::RELATION_SIDE_PARENT
            ? '_' . $collection->getSequence() . '_' . $relatedCollection->getSequence()
            : '_' . $relatedCollection->getSequence() . '_' . $collection->getSequence();
    }

    /**
     * Apply an operator to a relationship array of IDs
     *
     * @param Operator $operator
     * @param array<string> $existingIds
     * @return array<string|Document>
     */
    private function applyRelationshipOperator(Operator $operator, array $existingIds): array
    {
        $method = $operator->getMethod();
        $values = $operator->getValues();

        // Extract IDs from operator values (could be strings or Documents)
        $valueIds = \array_filter(\array_map(fn ($item) => $item instanceof Document ? $item->getId() : (\is_string($item) ? $item : null), $values));

        switch ($method) {
            case Operator::TYPE_ARRAY_APPEND:
                return \array_values(\array_merge($existingIds, $valueIds));

            case Operator::TYPE_ARRAY_PREPEND:
                return \array_values(\array_merge($valueIds, $existingIds));

            case Operator::TYPE_ARRAY_INSERT:
                $index = $values[0] ?? 0;
                $item = $values[1] ?? null;
                $itemId = $item instanceof Document ? $item->getId() : (\is_string($item) ? $item : null);
                if ($itemId !== null) {
                    \array_splice($existingIds, $index, 0, [$itemId]);
                }
                return \array_values($existingIds);

            case Operator::TYPE_ARRAY_REMOVE:
                $toRemove = $values[0] ?? null;
                if (\is_array($toRemove)) {
                    $toRemoveIds = \array_filter(\array_map(fn ($item) => $item instanceof Document ? $item->getId() : (\is_string($item) ? $item : null), $toRemove));
                    return \array_values(\array_diff($existingIds, $toRemoveIds));
                }
                $toRemoveId = $toRemove instanceof Document ? $toRemove->getId() : (\is_string($toRemove) ? $toRemove : null);
                if ($toRemoveId !== null) {
                    return \array_values(\array_diff($existingIds, [$toRemoveId]));
                }
                return $existingIds;

            case Operator::TYPE_ARRAY_UNIQUE:
                return \array_values(\array_unique($existingIds));

            case Operator::TYPE_ARRAY_INTERSECT:
                return \array_values(\array_intersect($existingIds, $valueIds));

            case Operator::TYPE_ARRAY_DIFF:
                return \array_values(\array_diff($existingIds, $valueIds));

            default:
                return $existingIds;
        }
    }

    /**
     * Create or update a document.
     *
     * @param string $collection
     * @param Document $document
     * @return Document
     * @throws StructureException
     * @throws Throwable
     */
    public function upsertDocument(
        string $collection,
        Document $document,
    ): Document {
        $result = null;

        $this->upsertDocumentsWithIncrease(
            $collection,
            '',
            [$document],
            function (Document $doc, ?Document $_old = null) use (&$result) {
                $result = $doc;
            }
        );

        if ($result === null) {
            // No-op (unchanged): return the current persisted doc
            $result = $this->getDocument($collection, $document->getId());
        }
        return $result;
    }

    /**
     * Create or update documents.
     *
     * @param string $collection
     * @param array<Document> $documents
     * @param int $batchSize
     * @param (callable(Document, ?Document): void)|null $onNext
     * @param (callable(Throwable): void)|null $onError
     * @return int
     * @throws StructureException
     * @throws \Throwable
     */
    public function upsertDocuments(
        string $collection,
        array $documents,
        int $batchSize = self::INSERT_BATCH_SIZE,
        ?callable $onNext = null,
        ?callable $onError = null
    ): int {
        return $this->upsertDocumentsWithIncrease(
            $collection,
            '',
            $documents,
            $onNext,
            $onError,
            $batchSize
        );
    }

    /**
     * Create or update documents, increasing the value of the given attribute by the value in each document.
     *
     * @param string $collection
     * @param string $attribute
     * @param array<Document> $documents
     * @param (callable(Document, ?Document): void)|null $onNext
     * @param (callable(Throwable): void)|null $onError
     * @param int $batchSize
     * @return int
     * @throws StructureException
     * @throws \Throwable
     * @throws Exception
     */
    public function upsertDocumentsWithIncrease(
        string $collection,
        string $attribute,
        array $documents,
        ?callable $onNext = null,
        ?callable $onError = null,
        int $batchSize = self::INSERT_BATCH_SIZE
    ): int {
        if (empty($documents)) {
            return 0;
        }

        $batchSize = \min(Database::INSERT_BATCH_SIZE, \max(1, $batchSize));
        $collection = $this->silent(fn () => $this->getCollection($collection));
        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $collectionAttributes = $collection->getAttribute('attributes', []);
        $time = DateTime::now();
        $created = 0;
        $updated = 0;
        $seenIds = [];
        $hasRelationships = !empty(\array_filter(
            $collectionAttributes,
            fn ($attribute) => $attribute['type'] === self::VAR_RELATIONSHIP
        ));

        // Batch-fetch existing documents in one query instead of N individual getDocument() calls.
        // tenantPerDocument: group ids by tenant and run one find() per tenant under withTenant,
        // so cross-tenant batches (e.g. StatsUsage worker) don't get silently scoped to the
        // session tenant and miss rows belonging to other tenants.
        $existingDocs = [];

        if ($this->getSharedTables() && $this->getTenantPerDocument()) {
            $idsByTenant = [];
            foreach ($documents as $doc) {
                if ($doc->getId() !== '') {
                    $idsByTenant[$doc->getTenant()][] = $doc->getId();
                }
            }
            foreach ($idsByTenant as $tenant => $tenantIds) {
                $tenantIds = \array_values(\array_unique($tenantIds));
                foreach (\array_chunk($tenantIds, \max(1, $this->maxQueryValues)) as $chunk) {
                    $found = $this->authorization->skip(fn () => $this->withTenant($tenant, fn () => $this->silent(
                        fn () => $this->find($collection->getId(), [
                            Query::equal('$id', $chunk),
                            Query::limit($this->maxQueryValues),
                        ])
                    )));
                    foreach ($found as $doc) {
                        $existingDocs[$this->tenantKey($doc)] = $doc;
                    }
                }
            }
        } else {
            $docIds = \array_values(\array_unique(\array_filter(
                \array_map(fn (Document $doc) => $doc->getId(), $documents),
                fn ($id) => $id !== ''
            )));

            if (!empty($docIds)) {
                foreach (\array_chunk($docIds, \max(1, $this->maxQueryValues)) as $chunk) {
                    $existing = $this->authorization->skip(fn () => $this->silent(
                        fn () => $this->find($collection->getId(), [
                            Query::equal('$id', $chunk),
                            Query::limit($this->maxQueryValues),
                        ])
                    ));
                    foreach ($existing as $doc) {
                        $existingDocs[$this->tenantKey($doc)] = $doc;
                    }
                }
            }
        }

        foreach ($documents as $key => $document) {
            $old = $existingDocs[$this->tenantKey($document)] ?? new Document();

            $document = $this->removeUnknownAttributes($collection, $document);

            // Extract operators early to avoid comparison issues
            $documentArray = $document->getArrayCopy();
            $extracted = Operator::extractOperators($documentArray);
            $operators = $extracted['operators'];
            $regularUpdates = $extracted['updates'];

            $internalKeys = \array_map(
                fn ($attr) => $attr['$id'],
                self::INTERNAL_ATTRIBUTES
            );

            $regularUpdatesUserOnly = \array_diff_key($regularUpdates, \array_flip($internalKeys));

            $skipPermissionsUpdate = true;

            if ($document->offsetExists('$permissions')) {
                $originalPermissions = $old->getPermissions();
                $currentPermissions = $document->getPermissions();

                sort($originalPermissions);
                sort($currentPermissions);

                $skipPermissionsUpdate = ($originalPermissions === $currentPermissions);
            }

            // Only skip if no operators and regular attributes haven't changed
            $hasChanges = false;
            if (!empty($operators)) {
                $hasChanges = true;
            } elseif (!empty($attribute)) {
                $hasChanges = true;
            } elseif (!$skipPermissionsUpdate) {
                $hasChanges = true;
            } else {
                // Check if any of the provided attributes differ from old document
                $oldAttributes = $old->getAttributes();
                foreach ($regularUpdatesUserOnly as $attrKey => $value) {
                    $oldValue = $oldAttributes[$attrKey] ?? null;
                    if ($oldValue != $value) {
                        $hasChanges = true;
                        break;
                    }
                }

                // Also check if old document has attributes that new document doesn't
                if (!$hasChanges) {
                    $internalKeys = \array_map(
                        fn ($attr) => $attr['$id'],
                        self::INTERNAL_ATTRIBUTES
                    );

                    $oldUserAttributes = array_diff_key($oldAttributes, array_flip($internalKeys));

                    foreach (array_keys($oldUserAttributes) as $oldAttrKey) {
                        if (!array_key_exists($oldAttrKey, $regularUpdatesUserOnly)) {
                            // Old document has an attribute that new document doesn't
                            $hasChanges = true;
                            break;
                        }
                    }
                }
            }

            if (!$hasChanges) {
                // If not updating a single attribute and the document is the same as the old one, skip it
                unset($documents[$key]);
                continue;
            }

            // If old is empty, check if user has create permission on the collection
            // If old is not empty, check if user has update permission on the collection
            // If old is not empty AND documentSecurity is enabled, check if user has update permission on the collection or document


            if ($old->isEmpty()) {
                if (!$this->authorization->isValid(new Input(self::PERMISSION_CREATE, $collection->getCreate()))) {
                    throw new AuthorizationException($this->authorization->getDescription());
                }
            } elseif (!$this->authorization->isValid(new Input(self::PERMISSION_UPDATE, [
                ...$collection->getUpdate(),
                ...($documentSecurity ? $old->getUpdate() : [])
            ]))) {
                throw new AuthorizationException($this->authorization->getDescription());
            }

            $updatedAt = $document->getUpdatedAt();

            $document
                ->setAttribute('$id', empty($document->getId()) ? ID::unique() : $document->getId())
                ->setAttribute('$collection', $collection->getId())
                ->setAttribute('$updatedAt', ($updatedAt === null || !$this->preserveDates) ? $time : $updatedAt);

            if (!$this->preserveSequence) {
                $document->removeAttribute('$sequence');
            }

            $createdAt = $document->getCreatedAt();
            if ($createdAt === null || !$this->preserveDates) {
                $document->setAttribute('$createdAt', $old->isEmpty() ? $time : $old->getCreatedAt());
            } else {
                $document->setAttribute('$createdAt', $createdAt);
            }

            // Force matching optional parameter sets
            // Doesn't use decode as that intentionally skips null defaults to reduce payload size
            foreach ($collectionAttributes as $attr) {
                if (!$attr->getAttribute('required') && !\array_key_exists($attr['$id'], (array)$document)) {
                    $document->setAttribute(
                        $attr['$id'],
                        $old->getAttribute($attr['$id'], ($attr['default'] ?? null))
                    );
                }
            }

            if ($skipPermissionsUpdate) {
                $document->setAttribute('$permissions', $old->getPermissions());
            }

            if ($this->adapter->getSharedTables()) {
                if ($this->adapter->getTenantPerDocument()) {
                    if ($document->getTenant() === null) {
                        throw new DatabaseException('Missing tenant. Tenant must be set when tenant per document is enabled.');
                    }
                    if (!$old->isEmpty() && $old->getTenant() != $document->getTenant()) {
                        throw new DatabaseException('Tenant cannot be changed.');
                    }
                } else {
                    $document->setAttribute('$tenant', $this->adapter->getTenant());
                }
            }

            $document = $this->encode($collection, $document);

            if ($this->validate) {
                $validator = new Structure(
                    $collection,
                    $this->adapter->getIdAttributeType(),
                    $this->adapter->getMinDateTime(),
                    $this->adapter->getMaxDateTime(),
                    $this->adapter->getSupportForAttributes(),
                    supportUnsignedBigInt: $this->adapter->getSupportForUnsignedBigInt(),
                    currentDocument: $old->isEmpty() ? null : $old
                );

                if (!$validator->isValid($document)) {
                    throw new StructureException($validator->getDescription());
                }
            }

            if (!$old->isEmpty()) {
                // Check if document was updated after the request timestamp
                try {
                    $oldUpdatedAt = new \DateTime($old->getUpdatedAt());
                } catch (Exception $e) {
                    throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
                }

                if (!\is_null($this->timestamp) && $oldUpdatedAt > $this->timestamp) {
                    throw new ConflictException('Document was updated after the request timestamp');
                }
            }

            if ($this->resolveRelationships) {
                $document = $this->silent(fn () => $this->createDocumentRelationships($collection, $document));
            }

            $seenIds[] = $this->tenantKey($document);
            $old = $this->adapter->castingBefore($collection, $old);
            $document = $this->adapter->castingBefore($collection, $document);

            $documents[$key] = new Change(
                old: $old,
                new: $document
            );
        }

        // Required because *some* DBs will allow duplicate IDs for upsert
        if (\count($seenIds) !== \count(\array_unique($seenIds))) {
            throw new DuplicateException('Duplicate document IDs found in the input array.');
        }

        foreach (\array_chunk($documents, $batchSize) as $chunk) {
            /**
             * @var array<Change> $chunk
             */
            $batch = $this->withTransaction(fn () => $this->authorization->skip(fn () => $this->adapter->upsertDocuments(
                $collection,
                $attribute,
                $chunk
            )));

            // Every row that already existed was read into $existingDocs above, so its
            // sequence is already in hand and does not need fetching a second time.
            foreach ($batch as $index => $doc) {
                if (empty($doc->getSequence()) && !empty($chunk[$index]->getOld()->getSequence())) {
                    $doc->setAttribute('$sequence', $chunk[$index]->getOld()->getSequence());
                }
            }

            // Nothing leaves this method except through $onNext -- the return value is a
            // count -- so a caller that passes none never sees these documents, and the work
            // that finishes them has no reader. Bulk writers such as the usage/stats workers
            // upsert several batches a second and read nothing back.
            if ($onNext !== null || $hasRelationships) {
                $batch = $this->adapter->getSequences($collection->getId(), $batch);
            }

            foreach ($chunk as $change) {
                if ($change->getOld()->isEmpty()) {
                    $created++;
                } else {
                    $updated++;
                }
            }

            if (!$this->inBatchRelationshipPopulation && $this->resolveRelationships) {
                $batch = $this->silent(fn () => $this->populateDocumentsRelationships($batch, $collection, $this->relationshipFetchDepth));
            }

            // Check if any document in the batch contains operators
            $hasOperators = false;
            foreach ($batch as $doc) {
                $extracted = Operator::extractOperators($doc->getArrayCopy());
                if (!empty($extracted['operators'])) {
                    $hasOperators = true;
                    break;
                }
            }

            // Refetching only exists to hand computed operator values back to the caller, and
            // $onNext is the only way anything leaves this method -- the return value is a
            // count. $hasOperators still has to reflect the batch, because the decode below
            // keys off it.
            if ($hasOperators && $onNext !== null) {
                $batch = $this->refetchDocuments($collection, $batch);
            }

            foreach ($batch as $index => $doc) {
                $doc = $this->adapter->castingAfter($collection, $doc);
                if (!$hasOperators) {
                    $doc = $this->decode($collection, $doc);
                }

                $this->withDocumentTenant($doc, fn () => $this->purgeCachedDocument($collection->getId(), $doc->getId()));

                $old = $chunk[$index]->getOld();

                if (!$old->isEmpty()) {
                    $old = $this->adapter->castingAfter($collection, $old);
                }

                try {
                    $onNext && $onNext($doc, $old->isEmpty() ? null : $old);
                } catch (\Throwable $th) {
                    $onError ? $onError($th) : throw $th;
                }
            }
        }

        $this->trigger(self::EVENT_DOCUMENTS_UPSERT, new Document([
            '$collection' => $collection->getId(),
            'created' => $created,
            'updated' => $updated,
        ]));

        return $created + $updated;
    }

    /**
     * Increase a document attribute by a value
     *
     * @param string $collection The collection ID
     * @param string $id The document ID
     * @param string $attribute The attribute to increase
     * @param int|float $value The value to increase the attribute by, can be a float
     * @param int|float|null $max The maximum value the attribute can reach after the increase, null means no limit
     * @return Document
     * @throws AuthorizationException
     * @throws DatabaseException
     * @throws LimitException
     * @throws NotFoundException
     * @throws TypeException
     * @throws \Throwable
     */
    public function increaseDocumentAttribute(
        string $collection,
        string $id,
        string $attribute,
        int|float $value = 1,
        int|float|null $max = null
    ): Document {
        if ($value <= 0) { // Can be a float
            throw new \InvalidArgumentException('Value must be numeric and greater than 0');
        }

        $collection = $this->silent(fn () => $this->getCollection($collection));
        if ($this->adapter->getSupportForAttributes()) {
            $attr = \array_filter($collection->getAttribute('attributes', []), function ($a) use ($attribute) {
                return $a['$id'] === $attribute;
            });

            if (empty($attr)) {
                throw new NotFoundException('Attribute not found');
            }

            $whiteList = [
                self::VAR_INTEGER,
                self::VAR_BIGINT,
                self::VAR_FLOAT
            ];

            /** @var Document $attr */
            $attr = \end($attr);
            if (!\in_array($attr->getAttribute('type'), $whiteList) || $attr->getAttribute('array')) {
                throw new TypeException('Attribute must be an integer or float and can not be an array.');
            }
        }

        $document = $this->withTransaction(function () use ($collection, $id, $attribute, $value, $max) {
            /* @var $document Document */
            $document = $this->authorization->skip(fn () => $this->silent(fn () => $this->getDocument($collection->getId(), $id, forUpdate: true))); // Skip ensures user does not need read permission for this

            if ($document->isEmpty()) {
                throw new NotFoundException('Document not found');
            }

            if ($collection->getId() !== self::METADATA) {
                $documentSecurity = $collection->getAttribute('documentSecurity', false);

                if (!$this->authorization->isValid(new Input(self::PERMISSION_UPDATE, [
                    ...$collection->getUpdate(),
                    ...($documentSecurity ? $document->getUpdate() : [])
                ]))) {
                    throw new AuthorizationException($this->authorization->getDescription());
                }
            }

            if (!\is_null($max) && ($document->getAttribute($attribute) + $value > $max)) {
                throw new LimitException('Attribute value exceeds maximum limit: ' . $max);
            }

            $time = DateTime::now();
            $updatedAt = $document->getUpdatedAt();
            $updatedAt = (empty($updatedAt) || !$this->preserveDates) ? $time : DateTime::format(new \DateTime($updatedAt));
            $max = $max ? $max - $value : null;

            $this->adapter->increaseDocumentAttribute(
                $collection->getId(),
                $id,
                $attribute,
                $value,
                $updatedAt,
                max: $max
            );

            return $document->setAttribute(
                $attribute,
                $document->getAttribute($attribute) + $value
            );
        });

        $this->purgeCachedDocument($collection->getId(), $id);

        $this->trigger(self::EVENT_DOCUMENT_INCREASE, $document);

        return $document;
    }


    /**
     * Decrease a document attribute by a value
     *
     * @param string $collection
     * @param string $id
     * @param string $attribute
     * @param int|float $value
     * @param int|float|null $min
     * @return Document
     *
     * @throws AuthorizationException
     * @throws DatabaseException
     */
    public function decreaseDocumentAttribute(
        string $collection,
        string $id,
        string $attribute,
        int|float $value = 1,
        int|float|null $min = null
    ): Document {
        if ($value <= 0) { // Can be a float
            throw new \InvalidArgumentException('Value must be numeric and greater than 0');
        }

        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($this->adapter->getSupportForAttributes()) {
            $attr = \array_filter($collection->getAttribute('attributes', []), function ($a) use ($attribute) {
                return $a['$id'] === $attribute;
            });

            if (empty($attr)) {
                throw new NotFoundException('Attribute not found');
            }

            $whiteList = [
                self::VAR_INTEGER,
                self::VAR_BIGINT,
                self::VAR_FLOAT
            ];

            /**
             * @var Document $attr
             */
            $attr = \end($attr);
            if (!\in_array($attr->getAttribute('type'), $whiteList) || $attr->getAttribute('array')) {
                throw new TypeException('Attribute must be an integer or float and can not be an array.');
            }
        }

        $document = $this->withTransaction(function () use ($collection, $id, $attribute, $value, $min) {
            /* @var $document Document */
            $document = $this->authorization->skip(fn () => $this->silent(fn () => $this->getDocument($collection->getId(), $id, forUpdate: true))); // Skip ensures user does not need read permission for this

            if ($document->isEmpty()) {
                throw new NotFoundException('Document not found');
            }

            if ($collection->getId() !== self::METADATA) {
                $documentSecurity = $collection->getAttribute('documentSecurity', false);

                if (!$this->authorization->isValid(new Input(self::PERMISSION_UPDATE, [
                    ...$collection->getUpdate(),
                    ...($documentSecurity ? $document->getUpdate() : [])
                ]))) {
                    throw new AuthorizationException($this->authorization->getDescription());
                }
            }

            if (!\is_null($min) && ($document->getAttribute($attribute) - $value < $min)) {
                throw new LimitException('Attribute value exceeds minimum limit: ' . $min);
            }

            $time = DateTime::now();
            $updatedAt = $document->getUpdatedAt();
            $updatedAt = (empty($updatedAt) || !$this->preserveDates) ? $time : DateTime::format(new \DateTime($updatedAt));
            $min = $min ? $min + $value : null;

            $this->adapter->increaseDocumentAttribute(
                $collection->getId(),
                $id,
                $attribute,
                $value * -1,
                $updatedAt,
                min: $min
            );

            return $document->setAttribute(
                $attribute,
                $document->getAttribute($attribute) - $value
            );
        });

        $this->purgeCachedDocument($collection->getId(), $id);

        $this->trigger(self::EVENT_DOCUMENT_DECREASE, $document);

        return $document;
    }

    /**
     * Delete Document
     *
     * Also fires EVENT_DOCUMENT_UPDATE for each document on the other side of a two-way relationship that the delete changed.
     *
     * @param string $collection
     * @param string $id
     *
     * @return bool
     *
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws RestrictedException
     */
    public function deleteDocument(string $collection, string $id): bool
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        $related = [];

        $deleted = $this->withTransaction(function () use ($collection, $id, &$document, &$related) {
            $document = $this->authorization->skip(fn () => $this->silent(
                fn () => $this->getDocument($collection->getId(), $id, forUpdate: true)
            ));

            if ($document->isEmpty()) {
                return false;
            }

            if ($collection->getId() !== self::METADATA) {
                $documentSecurity = $collection->getAttribute('documentSecurity', false);

                if (!$this->authorization->isValid(new Input(self::PERMISSION_DELETE, [
                    ...$collection->getDelete(),
                    ...($documentSecurity ? $document->getDelete() : [])
                ]))) {
                    throw new AuthorizationException($this->authorization->getDescription());
                }
            }

            // Check if document was updated after the request timestamp
            try {
                $oldUpdatedAt = new \DateTime($document->getUpdatedAt());
            } catch (Exception $e) {
                throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
            }

            if (!\is_null($this->timestamp) && $oldUpdatedAt > $this->timestamp) {
                throw new ConflictException('Document was updated after the request timestamp');
            }

            if ($this->resolveRelationships) {
                // A delete made while silenced, like a cascade's, has no one to report to
                $report = $this->silentListeners !== null;
                $related = $this->silent(fn () => $this->deleteDocumentRelationships($collection, $document, $report));
            }

            $result = $this->adapter->deleteDocument($collection->getId(), $id);

            $this->purgeCachedDocument($collection->getId(), $id);

            return $result;
        });

        if ($deleted) {
            // Purge again after commit so readers cannot re-cache the pre-commit version
            $this->purgeCachedDocumentInternal($collection->getId(), $id);
            $this->trigger(self::EVENT_DOCUMENT_DELETE, $document);

            foreach ($related as $relation) {
                $this->trigger(self::EVENT_DOCUMENT_UPDATE, $relation);
            }
        }

        return $deleted;
    }

    /**
     * @param Document $collection
     * @param Document $document
     * @param bool $report
     * @return array<string, Document> The two-way related documents left changed, when $report is set
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws RestrictedException
     * @throws StructureException
     */
    private function deleteDocumentRelationships(Document $collection, Document $document, bool $report = false): array
    {
        $related = [];
        $cascaded = false;

        $attributes = $collection->getAttribute('attributes', []);

        $relationships = \array_filter($attributes, function ($attribute) {
            return $attribute['type'] === Database::VAR_RELATIONSHIP;
        });

        foreach ($relationships as $relationship) {
            $key = $relationship['key'];
            $value = $document->getAttribute($key);
            $relatedCollection = $this->getCollection($relationship['options']['relatedCollection']);
            $relationType = $relationship['options']['relationType'];
            $twoWay = $relationship['options']['twoWay'];
            $twoWayKey = $relationship['options']['twoWayKey'];
            $onDelete = $relationship['options']['onDelete'];
            $side = $relationship['options']['side'];

            $relationship->setAttribute('collection', $collection->getId());
            $relationship->setAttribute('document', $document->getId());

            // This side holds the key, so deleting it takes the reference with it and nothing writes the other side
            $holdsKey =
                ($relationType === Database::RELATION_ONE_TO_MANY && $side === Database::RELATION_SIDE_CHILD) ||
                ($relationType === Database::RELATION_MANY_TO_ONE && $side === Database::RELATION_SIDE_PARENT);

            // Whether the other side survives this delete without being written to
            $unwritten = false;

            switch ($onDelete) {
                case Database::RELATION_MUTATE_RESTRICT:
                    $this->deleteRestrict($relatedCollection, $document, $value, $relationType, $twoWay, $twoWayKey, $side);
                    $unwritten = true;
                    break;
                case Database::RELATION_MUTATE_SET_NULL:
                    $updated = $this->deleteSetNull($collection, $relatedCollection, $document, $relationType, $twoWay, $twoWayKey, $side);

                    if ($twoWay) {
                        foreach ($updated as $relation) {
                            $related[$relatedCollection->getId() . ':' . $relation->getId()] = $relation;
                        }
                    }

                    $unwritten = $holdsKey || $relationType === Database::RELATION_MANY_TO_MANY;
                    break;
                case Database::RELATION_MUTATE_CASCADE:
                    $unwritten = $holdsKey || ($relationType === Database::RELATION_MANY_TO_MANY && $side === Database::RELATION_SIDE_CHILD);

                    foreach ($this->relationshipDeleteStack as $processedRelationship) {
                        $existingKey = $processedRelationship['key'];
                        $existingCollection = $processedRelationship['collection'];
                        $existingRelatedCollection = $processedRelationship['options']['relatedCollection'];
                        $existingTwoWayKey = $processedRelationship['options']['twoWayKey'];
                        $existingSide = $processedRelationship['options']['side'];

                        // If this relationship has already been fetched for this document, skip it
                        $reflexive = $processedRelationship == $relationship;

                        // If this relationship is the same as a previously fetched relationship, but on the other side, skip it
                        $symmetric = $existingKey === $twoWayKey
                            && $existingTwoWayKey === $key
                            && $existingRelatedCollection === $collection->getId()
                            && $existingCollection === $relatedCollection->getId()
                            && $existingSide !== $side;

                        // If this relationship is not directly related but relates across multiple collections, skip it.
                        //
                        // These conditions ensure that a relationship is considered transitive if it has the same
                        // two-way key and related collection, but is on the opposite side of the relationship (the first and second conditions).
                        //
                        // They also ensure that a relationship is considered transitive if it has the same key and related
                        // collection as an existing relationship, but a different two-way key (the third condition),
                        // or the same two-way key as an existing relationship, but a different key (the fourth condition).
                        $transitive = (($existingKey === $twoWayKey
                                && $existingCollection === $relatedCollection->getId()
                                && $existingSide !== $side)
                            || ($existingTwoWayKey === $key
                                && $existingRelatedCollection === $collection->getId()
                                && $existingSide !== $side)
                            || ($existingKey === $key
                                && $existingTwoWayKey !== $twoWayKey
                                && $existingRelatedCollection === $relatedCollection->getId()
                                && $existingSide !== $side)
                            || ($existingKey !== $key
                                && $existingTwoWayKey === $twoWayKey
                                && $existingRelatedCollection === $relatedCollection->getId()
                                && $existingSide !== $side));

                        if ($reflexive || $symmetric || $transitive) {
                            break 2;
                        }
                    }
                    $this->deleteCascade($collection, $relatedCollection, $document, $key, $value, $relationType, $twoWayKey, $side, $relationship);
                    break;
            }

            foreach (\is_array($value) ? $value : [$value] as $relation) {
                if (!$relation instanceof Document || $relation->isEmpty()) {
                    continue;
                }

                if ($onDelete === Database::RELATION_MUTATE_CASCADE && !$unwritten) {
                    $cascaded = true;
                } elseif ($twoWay && $unwritten) {
                    $related[$relatedCollection->getId() . ':' . $relation->getId()] = $relation;
                }
            }
        }

        if (!$report) {
            return [];
        }

        // A document related to itself is deleted, not changed
        unset($related[$collection->getId() . ':' . $document->getId()]);

        if (!$cascaded || empty($related)) {
            return $related;
        }

        // A cascade can remove a related document anywhere down its chain, so keep only the ones still there
        $idsByCollection = [];
        foreach ($related as $relation) {
            $idsByCollection[$relation->getCollection()][] = $relation->getId();
        }

        $existing = [];
        foreach ($idsByCollection as $collectionId => $ids) {
            foreach (\array_chunk($ids, \max(1, $this->maxQueryValues)) as $chunk) {
                $found = $this->authorization->skip(fn () => $this->find($collectionId, [
                    Query::equal('$id', $chunk),
                    Query::select(['$id']),
                    Query::limit(\count($chunk)),
                ]));

                foreach ($found as $doc) {
                    $existing[$collectionId][$doc->getId()] = true;
                }
            }
        }

        return \array_filter(
            $related,
            fn (Document $relation) => isset($existing[$relation->getCollection()][$relation->getId()]),
        );
    }

    /**
     * @param Document $relatedCollection
     * @param Document $document
     * @param mixed $value
     * @param string $relationType
     * @param bool $twoWay
     * @param string $twoWayKey
     * @param string $side
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws RestrictedException
     * @throws StructureException
     */
    private function deleteRestrict(
        Document $relatedCollection,
        Document $document,
        mixed $value,
        string $relationType,
        bool $twoWay,
        string $twoWayKey,
        string $side
    ): void {
        if ($value instanceof Document && $value->isEmpty()) {
            $value = null;
        }

        if (
            !empty($value)
            && $relationType !== Database::RELATION_MANY_TO_ONE
            && $side === Database::RELATION_SIDE_PARENT
        ) {
            throw new RestrictedException('Cannot delete document because it has at least one related document.');
        }

        if (
            $relationType === Database::RELATION_ONE_TO_ONE
            && $side === Database::RELATION_SIDE_CHILD
            && !$twoWay
        ) {
            $this->authorization->skip(function () use ($document, $relatedCollection, $twoWayKey) {
                $related = $this->findOne($relatedCollection->getId(), [
                    Query::select(['$id']),
                    Query::equal($twoWayKey, [$document->getId()])
                ]);

                if ($related->isEmpty()) {
                    return;
                }

                $this->skipRelationships(fn () => $this->updateDocument(
                    $relatedCollection->getId(),
                    $related->getId(),
                    new Document([
                        $twoWayKey => null
                    ])
                ));
            });
        }

        if (
            $relationType === Database::RELATION_MANY_TO_ONE
            && $side === Database::RELATION_SIDE_CHILD
        ) {
            $related = $this->authorization->skip(fn () => $this->findOne($relatedCollection->getId(), [
                Query::select(['$id']),
                Query::equal($twoWayKey, [$document->getId()])
            ]));

            if (!$related->isEmpty()) {
                throw new RestrictedException('Cannot delete document because it has at least one related document.');
            }
        }
    }

    /**
     * Find every document in $relatedCollection whose $twoWayKey points at $document.
     *
     * Deletes can start from a document fetched without its relationships populated -
     * deleteDocuments() passes the caller's queries straight to find(), and a select
     * query turns relationship population off - so the relationship value carried on
     * the document cannot be trusted here.
     *
     * Permissions are skipped: a referencing document the caller cannot read still has
     * to have its foreign key cleared, or it is left pointing at a deleted row.
     *
     * @return array<Document>
     * @throws DatabaseException
     */
    private function findReferencingDocuments(Document $relatedCollection, Document $document, string $twoWayKey): array
    {
        return $this->authorization->skip(fn () => $this->find($relatedCollection->getId(), [
            Query::select(['$id']),
            Query::equal($twoWayKey, [$document->getId()]),
            Query::limit(PHP_INT_MAX)
        ]));
    }

    /**
     * @param Document $collection
     * @param Document $relatedCollection
     * @param Document $document
     * @param string $relationType
     * @param bool $twoWay
     * @param string $twoWayKey
     * @param string $side
     * @return array<Document> The documents written
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws RestrictedException
     * @throws StructureException
     */
    private function deleteSetNull(Document $collection, Document $relatedCollection, Document $document, string $relationType, bool $twoWay, string $twoWayKey, string $side): array
    {
        $updated = [];

        switch ($relationType) {
            case Database::RELATION_ONE_TO_ONE:
                if (!$twoWay && $side === Database::RELATION_SIDE_PARENT) {
                    break;
                }

                // Shouldn't need read or update permission to delete
                $result = $this->authorization->skip(function () use ($document, $relatedCollection, $twoWayKey) {
                    $related = $this->findOne($relatedCollection->getId(), [
                        Query::select(['$id']),
                        Query::equal($twoWayKey, [$document->getId()])
                    ]);

                    if ($related->isEmpty()) {
                        return;
                    }

                    return $this->skipRelationships(fn () => $this->updateDocument(
                        $relatedCollection->getId(),
                        $related->getId(),
                        new Document([
                            $twoWayKey => null
                        ])
                    ));
                });

                if ($result !== null && !$result->isEmpty()) {
                    $updated[] = $result;
                }
                break;

            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_CHILD) {
                    break;
                }

                $relations = $this->findReferencingDocuments($relatedCollection, $document, $twoWayKey);

                foreach ($relations as $relation) {
                    $result = $this->authorization->skip(function () use ($relatedCollection, $twoWayKey, $relation) {
                        return $this->skipRelationships(fn () => $this->updateDocument(
                            $relatedCollection->getId(),
                            $relation->getId(),
                            new Document([
                                $twoWayKey => null
                            ]),
                        ));
                    });

                    if (!$result->isEmpty()) {
                        $updated[] = $result;
                    }
                }
                break;

            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    break;
                }

                $relations = $this->findReferencingDocuments($relatedCollection, $document, $twoWayKey);

                foreach ($relations as $relation) {
                    $result = $this->authorization->skip(function () use ($relatedCollection, $twoWayKey, $relation) {
                        return $this->skipRelationships(fn () => $this->updateDocument(
                            $relatedCollection->getId(),
                            $relation->getId(),
                            new Document([
                                $twoWayKey => null
                            ])
                        ));
                    });

                    if (!$result->isEmpty()) {
                        $updated[] = $result;
                    }
                }
                break;

            case Database::RELATION_MANY_TO_MANY:
                $junction = $this->getJunctionCollection($collection, $relatedCollection, $side);

                $junctions = $this->find($junction, [
                    Query::select(['$id']),
                    Query::equal($twoWayKey, [$document->getId()]),
                    Query::limit(PHP_INT_MAX)
                ]);

                foreach ($junctions as $document) {
                    $this->skipRelationships(fn () => $this->deleteDocument(
                        $junction,
                        $document->getId()
                    ));
                }
                break;
        }

        return $updated;
    }

    /**
     * @param Document $collection
     * @param Document $relatedCollection
     * @param Document $document
     * @param string $key
     * @param mixed $value
     * @param string $relationType
     * @param string $twoWayKey
     * @param string $side
     * @param Document $relationship
     * @return void
     * @throws AuthorizationException
     * @throws ConflictException
     * @throws DatabaseException
     * @throws RestrictedException
     * @throws StructureException
     */
    private function deleteCascade(Document $collection, Document $relatedCollection, Document $document, string $key, mixed $value, string $relationType, string $twoWayKey, string $side, Document $relationship): void
    {
        switch ($relationType) {
            case Database::RELATION_ONE_TO_ONE:
                if ($value !== null) {
                    $this->relationshipDeleteStack[] = $relationship;

                    $this->deleteDocument(
                        $relatedCollection->getId(),
                        ($value instanceof Document) ? $value->getId() : $value
                    );

                    \array_pop($this->relationshipDeleteStack);
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_CHILD) {
                    break;
                }

                $this->relationshipDeleteStack[] = $relationship;

                foreach ($value as $relation) {
                    $this->deleteDocument(
                        $relatedCollection->getId(),
                        $relation->getId()
                    );
                }

                \array_pop($this->relationshipDeleteStack);

                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    break;
                }

                $value = $this->find($relatedCollection->getId(), [
                    Query::select(['$id']),
                    Query::equal($twoWayKey, [$document->getId()]),
                    Query::limit(PHP_INT_MAX),
                ]);

                $this->relationshipDeleteStack[] = $relationship;

                foreach ($value as $relation) {
                    $this->deleteDocument(
                        $relatedCollection->getId(),
                        $relation->getId()
                    );
                }

                \array_pop($this->relationshipDeleteStack);

                break;
            case Database::RELATION_MANY_TO_MANY:
                $junction = $this->getJunctionCollection($collection, $relatedCollection, $side);

                $junctions = $this->skipRelationships(fn () => $this->find($junction, [
                    Query::select(['$id', $key]),
                    Query::equal($twoWayKey, [$document->getId()]),
                    Query::limit(PHP_INT_MAX)
                ]));

                $this->relationshipDeleteStack[] = $relationship;

                foreach ($junctions as $document) {
                    if ($side === Database::RELATION_SIDE_PARENT) {
                        $this->deleteDocument(
                            $relatedCollection->getId(),
                            $document->getAttribute($key)
                        );
                    }
                    $this->deleteDocument(
                        $junction,
                        $document->getId()
                    );
                }

                \array_pop($this->relationshipDeleteStack);
                break;
        }
    }

    /**
     * Delete Documents
     *
     * Deletes all documents which match the given query, will respect the relationship's onDelete optin.
     *
     * @param string $collection
     * @param array<Query> $queries
     * @param int $batchSize
     * @param (callable(Document, Document): void)|null $onNext
     * @param (callable(Throwable): void)|null $onError
     * @return int
     * @throws AuthorizationException
     * @throws DatabaseException
     * @throws RestrictedException
     * @throws \Throwable
     */
    public function deleteDocuments(
        string $collection,
        array $queries = [],
        int $batchSize = self::DELETE_BATCH_SIZE,
        ?callable $onNext = null,
        ?callable $onError = null,
    ): int {
        if ($this->adapter->getSharedTables() && empty($this->adapter->getTenant())) {
            throw new DatabaseException('Missing tenant. Tenant must be set when table sharing is enabled.');
        }

        $batchSize = \min(Database::DELETE_BATCH_SIZE, \max(1, $batchSize));
        $collection = $this->silent(fn () => $this->getCollection($collection));
        if ($collection->isEmpty()) {
            throw new DatabaseException('Collection not found');
        }

        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $skipAuth = $this->authorization->isValid(new Input(self::PERMISSION_DELETE, $collection->getDelete()));

        if (!$skipAuth && !$documentSecurity && $collection->getId() !== self::METADATA) {
            throw new AuthorizationException($this->authorization->getDescription());
        }

        $attributes = $collection->getAttribute('attributes', []);
        $indexes = $collection->getAttribute('indexes', []);

        $this->checkQueryTypes($queries);

        if ($this->validate) {
            $validator = new DocumentsValidator(
                $attributes,
                $indexes,
                $this->adapter->getIdAttributeType(),
                $this->maxQueryValues,
                $this->adapter->getMaxUIDLength(),
                $this->adapter->getMinDateTime(),
                $this->adapter->getMaxDateTime(),
                $this->adapter->getSupportForAttributes(),
                $this->adapter->getSupportForUnsignedBigInt()
            );

            if (!$validator->isValid($queries)) {
                throw new QueryException($validator->getDescription());
            }
        }

        $grouped = Query::groupByType($queries);
        $limit = $grouped['limit'];
        $cursor = $grouped['cursor'];

        if (!empty($cursor) && $cursor->getCollection() !== $collection->getId()) {
            throw new DatabaseException("Cursor document must be from the same Collection.");
        }

        $originalLimit = $limit;
        $last = $cursor;
        $modified = 0;

        while (true) {
            if ($limit && $limit < $batchSize && $limit > 0) {
                $batchSize = $limit;
            } elseif (!empty($limit)) {
                $limit -= $batchSize;
            }

            $new = [
                Query::limit($batchSize)
            ];

            if (!empty($last)) {
                $new[] = Query::cursorAfter($last);
            }

            /**
             * @var array<Document> $batch
             */
            $batch = $this->silent(fn () => $this->find(
                $collection->getId(),
                array_merge($new, $queries),
                forPermission: Database::PERMISSION_DELETE
            ));

            if (empty($batch)) {
                break;
            }

            $old = array_map(fn ($doc) => clone $doc, $batch);
            $sequences = [];
            $permissionIds = [];

            $this->withTransaction(function () use ($collection, $sequences, $permissionIds, $batch) {
                foreach ($batch as $document) {
                    $sequences[] = $document->getSequence();
                    if (!empty($document->getPermissions())) {
                        $permissionIds[] = $document->getId();
                    }

                    if ($this->resolveRelationships) {
                        $this->silent(fn () => $this->deleteDocumentRelationships(
                            $collection,
                            $document
                        ));
                    }

                    // Check if document was updated after the request timestamp
                    try {
                        $oldUpdatedAt = new \DateTime($document->getUpdatedAt());
                    } catch (Exception $e) {
                        throw new DatabaseException($e->getMessage(), $e->getCode(), $e);
                    }

                    if (!\is_null($this->timestamp) && $oldUpdatedAt > $this->timestamp) {
                        throw new ConflictException('Document was updated after the request timestamp');
                    }
                }

                $this->adapter->deleteDocuments(
                    $collection->getId(),
                    $sequences,
                    $permissionIds
                );
            });

            foreach ($batch as $index => $document) {
                $this->withDocumentTenant($document, fn () => $this->purgeCachedDocument($collection->getId(), $document->getId()));
                try {
                    $onNext && $onNext($document, $old[$index]);
                } catch (Throwable $th) {
                    $onError ? $onError($th) : throw $th;
                }
                $modified++;
            }

            if (count($batch) < $batchSize) {
                break;
            } elseif ($originalLimit && $modified >= $originalLimit) {
                break;
            }

            $last = \end($batch);
        }

        $this->trigger(self::EVENT_DOCUMENTS_DELETE, new Document([
            '$collection' => $collection->getId(),
            'modified' => $modified
        ]));

        return $modified;
    }

    /**
     * Cleans the all the collection's documents from the cache
     * And the all related cached documents.
     *
     * @param string $collectionId
     *
     * @return bool
     */
    public function purgeCachedCollection(string $collectionId): bool
    {
        [$collectionKey] = $this->getCacheKeys($collectionId);

        $documentKeys = $this->cache->list($collectionKey);
        foreach ($documentKeys as $documentKey) {
            $this->cache->purge($documentKey);
        }

        $this->cache->purge($collectionKey);

        return true;
    }

    /**
     * Cleans a specific document from cache
     * And related document reference in the collection cache.
     *
     * @param string $collectionId
     * @param string|null $id
     * @return bool
     * @throws Exception
     */
    protected function purgeCachedDocumentInternal(string $collectionId, ?string $id): bool
    {
        if ($id === null) {
            return true;
        }

        [$collectionKey, $documentKey] = $this->getCacheBaseKeys($collectionId, $id);

        $this->cache->purge($collectionKey, $documentKey);
        $this->cache->purge($documentKey);

        return true;
    }

    /**
     * Run a per-document cache operation under the document's own tenant.
     *
     * With tenant-per-document, cache keys are scoped by the adapter's current
     * tenant, so a document's purge must run under that document's tenant to
     * target the right key; otherwise the callback runs as-is.
     *
     * @param Document $document
     * @param callable():mixed $callback
     * @return void
     * @throws Exception
     */
    private function withDocumentTenant(Document $document, callable $callback): void
    {
        if ($this->getSharedTables() && $this->getTenantPerDocument()) {
            $this->withTenant($document->getTenant(), $callback);
        } else {
            $callback();
        }
    }

    /**
     * Cleans a specific document from cache and triggers EVENT_DOCUMENT_PURGE.
     * And related document reference in the collection cache.
     *
     * Note: Do not retry this method as it triggers events. Use purgeCachedDocumentInternal() with retry instead.
     *
     * @param string $collectionId
     * @param string|null $id
     * @return bool
     * @throws Exception
     */
    public function purgeCachedDocument(string $collectionId, ?string $id): bool
    {
        $result = $this->purgeCachedDocumentInternal($collectionId, $id);

        if ($id !== null) {
            $this->trigger(self::EVENT_DOCUMENT_PURGE, new Document([
                '$id' => $id,
                '$collection' => $collectionId
            ]));
        }

        return $result;
    }

    /**
     * Find Documents
     *
     * @param string $collection
     * @param array<Query> $queries
     * @param string $forPermission
     * @return array<Document>
     * @throws DatabaseException
     * @throws QueryException
     * @throws TimeoutException
     * @throws Exception
     */
    public function find(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): array
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        $attributes = $collection->getAttribute('attributes', []);
        $indexes = $collection->getAttribute('indexes', []);

        $this->checkQueryTypes($queries);

        if ($this->validate) {
            $validator = new DocumentsValidator(
                $attributes,
                $indexes,
                $this->adapter->getIdAttributeType(),
                $this->maxQueryValues,
                $this->adapter->getMaxUIDLength(),
                $this->adapter->getMinDateTime(),
                $this->adapter->getMaxDateTime(),
                $this->adapter->getSupportForAttributes(),
                $this->adapter->getSupportForUnsignedBigInt()
            );
            if (!$validator->isValid($queries)) {
                throw new QueryException($validator->getDescription());
            }
        }

        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $skipAuth = $this->authorization->isValid(new Input($forPermission, $collection->getPermissionsByType($forPermission)));

        if (!$skipAuth && !$documentSecurity && $collection->getId() !== self::METADATA) {
            throw new AuthorizationException($this->authorization->getDescription());
        }

        $relationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn (Document $attribute) => $attribute->getAttribute('type') === self::VAR_RELATIONSHIP
        );

        $grouped = Query::groupByType($queries);
        $filters = $grouped['filters'];
        $selects = $grouped['selections'];
        $limit = $grouped['limit'];
        $offset = $grouped['offset'];
        $orderAttributes = $grouped['orderAttributes'];
        $orderTypes = $grouped['orderTypes'];
        $cursor = $grouped['cursor'];
        $cursorDirection = $grouped['cursorDirection'] ?? Database::CURSOR_AFTER;

        $uniqueOrderBy = false;
        foreach ($orderAttributes as $order) {
            if ($order === '$id' || $order === '$sequence') {
                $uniqueOrderBy = true;
            }
        }

        $vectorSearch = false;
        foreach ($filters as $filter) {
            if (\in_array($filter->getMethod(), Query::VECTOR_TYPES)) {
                $vectorSearch = true;
                break;
            }
        }

        // A vector search is ordered by distance, and a vector index can only answer that one
        // sort key. Appending a tie break makes the ordering unsatisfiable from the index and
        // costs a full scan of the collection. The tie break exists to hold a page boundary
        // still, so it is only owed to a cursor.
        if ($uniqueOrderBy === false && (!$vectorSearch || !empty($cursor))) {
            $leadingAttribute = $orderAttributes[0] ?? null;
            $leadingOrderType = $orderTypes[0] ?? Database::ORDER_ASC;

            if (\in_array($leadingAttribute, ['$createdAt', '$updatedAt'], true)) {
                $orderAttributes[] = '$sequence';
                $orderTypes[] = $leadingOrderType;
            } else {
                $orderAttributes[] = '$sequence';
                $orderTypes[] = Database::ORDER_ASC;
            }
        }

        if (!empty($cursor)) {
            foreach ($orderAttributes as $order) {
                if ($cursor->getAttribute($order) === null) {
                    throw new OrderException(
                        message: "Order attribute '{$order}' is empty",
                        attribute: $order
                    );
                }
            }
        }

        if (!empty($cursor) && $cursor->getCollection() !== $collection->getId()) {
            throw new DatabaseException("cursor Document must be from the same Collection.");
        }

        if (!empty($cursor)) {
            $cursor = $this->encode($collection, $cursor);
            $cursor = $this->adapter->castingBefore($collection, $cursor);
            $cursor = $cursor->getArrayCopy();
        } else {
            $cursor = [];
        }

        /**  @var array<Query> $queries */
        $queries = \array_merge(
            $selects,
            $this->convertQueries($collection, $filters)
        );

        $selections = $this->validateSelections($collection, $selects);
        $nestedSelections = $this->processRelationshipQueries($relationships, $queries);

        // Convert relationship filter queries to SQL-level subqueries
        $queriesOrNull = $this->convertRelationshipQueries($relationships, $queries, $collection);

        // If conversion returns null, it means no documents can match (relationship filter found no matches)
        if ($queriesOrNull === null) {
            $results = [];
        } else {
            $queries = $queriesOrNull;

            $getResults = fn () => $this->adapter->find(
                $collection,
                $queries,
                $limit ?? 25,
                $offset ?? 0,
                $orderAttributes,
                $orderTypes,
                $cursor,
                $cursorDirection,
                $forPermission
            );

            $results = $skipAuth ? $this->authorization->skip($getResults) : $getResults();
        }

        if (!$this->inBatchRelationshipPopulation && $this->resolveRelationships && !empty($relationships) && (empty($selects) || !empty($nestedSelections))) {
            if (count($results) > 0) {
                $results = $this->silent(fn () => $this->populateDocumentsRelationships($results, $collection, $this->relationshipFetchDepth, $nestedSelections));
            }
        }

        foreach ($results as $index => $node) {
            $node = $this->adapter->castingAfter($collection, $node);
            $node = $this->casting($collection, $node);
            $node = $this->decode($collection, $node, $selections);

            // Convert to custom document type if mapped
            if (isset($this->documentTypes[$collection->getId()])) {
                $node = $this->createDocumentInstance($collection->getId(), $node->getArrayCopy());
            }

            if (!$node->isEmpty()) {
                $node->setAttribute('$collection', $collection->getId());
            }

            $results[$index] = $node;
        }

        $this->trigger(self::EVENT_DOCUMENT_FIND, $results);

        return $results;
    }

    /**
     * Purge all cached query entries for a collection namespace.
     *
     * @param string $collection
     * @param string|null $namespace
     * @return bool
     */
    public function purgeCachedQueries(string $collection, ?string $namespace = null): bool
    {
        $collectionDocument = $this->silent(fn () => $this->getCollection($collection));
        $collection = $collectionDocument->isEmpty() ? $collection : $collectionDocument->getId();

        return $this->cache->purge(
            $this->getQueryCacheKey($collection, $namespace)
        );
    }

    /**
     * Execute a callback behind a cache-aside lookup.
     *
     * The callback runs on cache miss and its value is returned to the caller.
     * Query document payloads are converted to arrays before save and restored
     * back into Documents on cache hits. A rejected document payload refreshes
     * the cached value.
     * A literal false value is treated as a cache miss and is not cacheable.
     *
     * @template T
     * @param string $key
     * @param callable(): T $callback
     * @param string|null $hash
     * @return T
     * @throws AuthorizationException
     * @throws Exception
     */
    public function withCache(
        string $key,
        callable $callback,
        ?string $hash = '',
    ): mixed {
        if ($hash === null) {
            return $callback();
        }

        $shouldRefreshCache = false;

        try {
            $cached = $this->cache->load($key, self::TTL, $hash);
        } catch (Throwable $e) {
            Console::warning('Warning: Failed to load cache value: ' . $e->getMessage());
            $cached = false;
        }

        if ($cached !== false && $cached !== null) {
            $cachedValue = \is_array($cached) && \array_key_exists('value', $cached) ? $cached['value'] : false;

            if ($cachedValue !== false) {
                $decoded = $cachedValue;
                $collection = $cached['collection'] ?? null;

                if (\is_string($collection) && $collection !== '') {
                    // Cached document payloads are stored as arrays; restore them
                    // to the same Document shape that find()/getDocument() return.
                    $collection = $this->silent(fn () => $this->getCollection($collection));

                    if ($collection->isEmpty()) {
                        $decoded = false;
                    } else {
                        $documentSecurity = $collection->getAttribute('documentSecurity', false);
                        $skipAuth = $this->authorization->isValid(new Input(self::PERMISSION_READ, $collection->getRead()));

                        if (!$skipAuth && !$documentSecurity && $collection->getId() !== self::METADATA) {
                            throw new AuthorizationException($this->authorization->getDescription());
                        }

                        $payload = ($cached['type'] ?? null) === 'document' ? [$cachedValue] : $cachedValue;

                        if (!\is_array($payload)) {
                            $decoded = false;
                        } else {
                            $documents = [];

                            foreach ($payload as $document) {
                                if (!\is_array($document)) {
                                    $decoded = false;
                                    break;
                                }

                                $document = $this->createDocumentInstance($collection->getId(), $document);
                                $document = $this->casting($collection, $document);

                                if ($this->isTtlExpired($collection, $document)) {
                                    $decoded = false;
                                    break;
                                }

                                if (!$skipAuth && $documentSecurity && $collection->getId() !== self::METADATA) {
                                    if (!$this->authorization->isValid(new Input(self::PERMISSION_READ, $document->getRead()))) {
                                        if (($cached['type'] ?? null) === 'document') {
                                            $decoded = false;
                                            break;
                                        }

                                        continue;
                                    }
                                }

                                $documents[] = $document;
                            }

                            if ($decoded !== false) {
                                $decoded = ($cached['type'] ?? null) === 'document' ? ($documents[0] ?? false) : $documents;
                            }
                        }
                    }
                }

                if ($decoded !== false) {
                    return $decoded;
                }
            }

            $shouldRefreshCache = true;
        }

        if ($shouldRefreshCache) {
            try {
                $this->cache->purge($key, $hash);
            } catch (Throwable $e) {
                Console::warning('Warning: Failed to purge rejected cache value: ' . $e->getMessage());
            }
        }

        // Capture the generation before the callback runs its read: if a
        // concurrent write purges this query key in between, saveWithLease()
        // below rejects the now-stale list instead of re-poisoning the cache.
        $generation = '0';
        try {
            $generation = $this->cache->getGeneration($key);
        } catch (Throwable $e) {
            Console::warning('Warning: Failed to get cache generation: ' . $e->getMessage());
        }

        $callbackValue = $callback();

        if ($callbackValue !== false) {
            try {
                $encoded = false;

                if ($callbackValue instanceof Document) {
                    $collection = $callbackValue->getCollection();

                    if ($collection !== '') {
                        $encoded = [
                            'collection' => $collection,
                            'type' => 'document',
                            'value' => $callbackValue->getArrayCopy(),
                        ];
                    }
                } elseif (!\is_array($callbackValue)) {
                    $encoded = ['value' => $callbackValue];
                } else {
                    // Only homogeneous top-level document lists are safe to restore
                    // from cache. Plain arrays containing Documents are left uncached.
                    $collection = null;
                    $hasDocuments = false;
                    $hasNonDocuments = false;
                    $cacheable = true;
                    $documents = [];
                    $containsDocument = function (mixed $item) use (&$containsDocument): bool {
                        if ($item instanceof Document) {
                            return true;
                        }

                        if (!\is_array($item)) {
                            return false;
                        }

                        foreach ($item as $child) {
                            if ($containsDocument($child)) {
                                return true;
                            }
                        }

                        return false;
                    };

                    foreach ($callbackValue as $item) {
                        if (!$item instanceof Document) {
                            if ($hasDocuments || $containsDocument($item)) {
                                $cacheable = false;
                                break;
                            }

                            $hasNonDocuments = true;
                            continue;
                        }

                        if ($hasNonDocuments) {
                            $cacheable = false;
                            break;
                        }

                        $documentCollection = $item->getCollection();
                        if ($documentCollection === '') {
                            $cacheable = false;
                            break;
                        }

                        if ($collection !== null && $collection !== $documentCollection) {
                            $cacheable = false;
                            break;
                        }

                        $collection = $documentCollection;
                        $hasDocuments = true;
                        $documents[] = $item->getArrayCopy();
                    }

                    if ($cacheable) {
                        $encoded = $hasDocuments ? [
                            'collection' => $collection,
                            'type' => 'documents',
                            'value' => $documents,
                        ] : ['value' => $callbackValue];
                    }
                }

                if ($encoded !== false) {
                    $this->cache->saveWithLease($key, $encoded, $hash, $generation);
                }
            } catch (Throwable $e) {
                Console::warning('Warning: Failed to save cache value: ' . $e->getMessage());
            }
        }

        /** @var T $callbackValue */
        return $callbackValue;
    }

    /**
     * Helper method to iterate documents in collection using callback pattern
     * Alterative is
     *
     * @param string $collection
     * @param callable $callback
     * @param array<Query> $queries
     * @param string $forPermission
     * @return void
     * @throws \Utopia\Database\Exception
     */
    public function foreach(string $collection, callable $callback, array $queries = [], string $forPermission = Database::PERMISSION_READ): void
    {
        foreach ($this->iterate($collection, $queries, $forPermission) as $document) {
            $callback($document);
        }
    }

    /**
     * Return each document of the given collection
     * that matches the given queries
     *
     * @param string $collection
     * @param array<Query> $queries
     * @param string $forPermission
     * @return \Generator
     * @throws \Utopia\Database\Exception
     */
    public function iterate(string $collection, array $queries = [], string $forPermission = Database::PERMISSION_READ): \Generator
    {
        $grouped = Query::groupByType($queries);
        $limitExists = $grouped['limit'] !== null;
        $limit = $grouped['limit'] ?? 25;
        $offset = $grouped['offset'];

        $cursor = $grouped['cursor'];
        $cursorDirection = $grouped['cursorDirection'];

        // Cursor before is not supported
        if ($cursor !== null && $cursorDirection === Database::CURSOR_BEFORE) {
            throw new DatabaseException('Cursor ' . Database::CURSOR_BEFORE . ' not supported in this method.');
        }

        $sum = $limit;
        $latestDocument = null;

        while ($sum === $limit) {
            $newQueries = $queries;
            if ($latestDocument !== null) {
                //reset offset and cursor as groupByType ignores same type query after first one is encountered
                if ($offset !== null) {
                    array_unshift($newQueries, Query::offset(0));
                }

                array_unshift($newQueries, Query::cursorAfter($latestDocument));
            }
            if (!$limitExists) {
                $newQueries[] = Query::limit($limit);
            }
            $results = $this->find($collection, $newQueries, $forPermission);

            if (empty($results)) {
                return;
            }

            $sum = count($results);

            foreach ($results as $document) {
                yield $document;
            }

            $latestDocument = $results[array_key_last($results)];
        }
    }

    /**
     * @param string $collection
     * @param array<Query> $queries
     * @return Document
     * @throws DatabaseException
     */
    public function findOne(string $collection, array $queries = []): Document
    {
        $results = $this->silent(fn () => $this->find($collection, \array_merge([
            Query::limit(1)
        ], $queries)));

        $found = \reset($results);

        $this->trigger(self::EVENT_DOCUMENT_FIND, $found);

        if (!$found) {
            return new Document();
        }

        return $found;
    }

    /**
     * Count Documents
     *
     * Count the number of documents.
     *
     * @param string $collection
     * @param array<Query> $queries
     * @param int|null $max
     *
     * @return int
     * @throws DatabaseException
     */
    public function count(string $collection, array $queries = [], ?int $max = null): int
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        $attributes = $collection->getAttribute('attributes', []);
        $indexes = $collection->getAttribute('indexes', []);

        $this->checkQueryTypes($queries);

        if ($this->validate) {
            $validator = new DocumentsValidator(
                $attributes,
                $indexes,
                $this->adapter->getIdAttributeType(),
                $this->maxQueryValues,
                $this->adapter->getMaxUIDLength(),
                $this->adapter->getMinDateTime(),
                $this->adapter->getMaxDateTime(),
                $this->adapter->getSupportForAttributes(),
                $this->adapter->getSupportForUnsignedBigInt()
            );
            if (!$validator->isValid($queries)) {
                throw new QueryException($validator->getDescription());
            }
        }

        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $skipAuth = $this->authorization->isValid(new Input(self::PERMISSION_READ, $collection->getRead()));

        if (!$skipAuth && !$documentSecurity && $collection->getId() !== self::METADATA) {
            throw new AuthorizationException($this->authorization->getDescription());
        }

        $relationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn (Document $attribute) => $attribute->getAttribute('type') === self::VAR_RELATIONSHIP
        );

        $queries = Query::groupByType($queries)['filters'];
        $queries = $this->convertQueries($collection, $queries);

        $queriesOrNull = $this->convertRelationshipQueries($relationships, $queries, $collection);

        if ($queriesOrNull === null) {
            return 0;
        }

        $queries = $queriesOrNull;

        $getCount = fn () => $this->adapter->count($collection, $queries, $max);
        $count = $skipAuth ? $this->authorization->skip($getCount) : $getCount();

        $this->trigger(self::EVENT_DOCUMENT_COUNT, $count);

        return $count;
    }

    /**
     * Sum an attribute
     *
     * Sum an attribute for all the documents. Pass $max=0 for unlimited count
     *
     * @param string $collection
     * @param string $attribute
     * @param array<Query> $queries
     * @param int|null $max
     *
     * @return int|float
     * @throws DatabaseException
     */
    public function sum(string $collection, string $attribute, array $queries = [], ?int $max = null): float|int
    {
        $collection = $this->silent(fn () => $this->getCollection($collection));

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        $attributes = $collection->getAttribute('attributes', []);
        $indexes = $collection->getAttribute('indexes', []);

        $this->checkQueryTypes($queries);

        if ($this->validate) {
            $validator = new DocumentsValidator(
                $attributes,
                $indexes,
                $this->adapter->getIdAttributeType(),
                $this->maxQueryValues,
                $this->adapter->getMaxUIDLength(),
                $this->adapter->getMinDateTime(),
                $this->adapter->getMaxDateTime(),
                $this->adapter->getSupportForAttributes(),
                $this->adapter->getSupportForUnsignedBigInt()
            );
            if (!$validator->isValid($queries)) {
                throw new QueryException($validator->getDescription());
            }
        }

        $documentSecurity = $collection->getAttribute('documentSecurity', false);
        $skipAuth = $this->authorization->isValid(new Input(self::PERMISSION_READ, $collection->getRead()));

        if (!$skipAuth && !$documentSecurity && $collection->getId() !== self::METADATA) {
            throw new AuthorizationException($this->authorization->getDescription());
        }

        $relationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn (Document $attribute) => $attribute->getAttribute('type') === self::VAR_RELATIONSHIP
        );

        $queries = $this->convertQueries($collection, $queries);
        $queriesOrNull = $this->convertRelationshipQueries($relationships, $queries, $collection);

        // If conversion returns null, it means no documents can match (relationship filter found no matches)
        if ($queriesOrNull === null) {
            return 0;
        }

        $queries = $queriesOrNull;

        $getSum = fn () => $this->adapter->sum($collection, $attribute, $queries, $max);
        $sum = $skipAuth ? $this->authorization->skip($getSum) : $getSum();

        $this->trigger(self::EVENT_DOCUMENT_SUM, $sum);

        return $sum;
    }

    /**
     * Add Attribute Filter
     *
     * @param string $name
     * @param callable $encode
     * @param callable $decode
     *
     * @return void
     */
    public static function addFilter(string $name, callable $encode, callable $decode): void
    {
        self::registerDefaultFilters();

        self::$filters[$name] = [
            'encode' => $encode,
            'decode' => $decode,
            'signature' => self::computeCallableSignature($encode) . ':' . self::computeCallableSignature($decode),
        ];

        self::$filtersVersion++;
    }

    /**
     * Remove attributes the collection schema does not declare.
     *
     * Used ahead of change detection on update/upsert so a dropped key is not
     * counted as a write. Encode also calls this after iterating attributes.
     *
     * @param Document $collection
     * @param Document $document
     * @param array<string, true>|null $known Attribute ids already collected (e.g. during encode)
     *
     * @return Document
     */
    protected function removeUnknownAttributes(Document $collection, Document $document, ?array $known = null): Document
    {
        if (!$this->dropUnknownAttributes || !$this->adapter->getSupportForAttributes()) {
            return $document;
        }

        if ($known === null) {
            $known = [];
            foreach ($collection->getAttribute('attributes', []) as $attribute) {
                $known[$attribute['$id'] ?? ''] = true;
            }
        }

        $dropped = [];
        $documentKeys = [];
        foreach ($document as $key => $value) {
            $documentKeys[] = $key;
        }
        foreach ($documentKeys as $key) {
            if (\str_starts_with($key, '$') || isset($known[$key])) {
                continue;
            }

            $dropped[] = $key;
            $document->removeAttribute($key);
        }

        if (!empty($dropped)) {
            Console::warning(
                'Dropped unknown attributes "' . \implode('", "', $dropped) . '" from collection "' . $collection->getId() . '"'
                . ($this->adapter->getTenant() === null ? '' : ' on tenant ' . $this->adapter->getTenant())
            );
        }

        return $document;
    }

    /**
     * Encode Document
     *
     * When dropUnknownAttributes is enabled, attributes missing from the
     * collection schema are removed here while the known set is collected.
     *
     * @param Document $collection
     * @param Document $document
     * @param bool $applyDefaults Whether to apply default values to null attributes
     *
     * @return Document
     * @throws DatabaseException
     */
    public function encode(Document $collection, Document $document, bool $applyDefaults = true): Document
    {
        $attributes = $collection->getAttribute('attributes', []);
        $internalDateAttributes = ['$createdAt', '$updatedAt'];
        foreach ($this->getInternalAttributes() as $attribute) {
            $attributes[] = $attribute;
        }

        $known = [];
        foreach ($attributes as $attribute) {
            $key = $attribute['$id'] ?? '';
            $known[$key] = true;
            $array = $attribute['array'] ?? false;
            $default = $attribute['default'] ?? null;
            $filters = $attribute['filters'] ?? [];
            $value = $document->getAttribute($key);

            if (in_array($key, $internalDateAttributes) && is_string($value) && empty($value)) {
                $document->setAttribute($key, null);
                continue;
            }

            if ($key === '$permissions') {
                continue;
            }

            // Continue on optional param with no default
            if (is_null($value) && is_null($default)) {
                continue;
            }

            // Skip encoding for Operator objects
            if ($value instanceof Operator) {
                continue;
            }

            // Assign default only if no value provided
            // False positive "Call to function is_null() with mixed will always evaluate to false"
            // @phpstan-ignore-next-line
            if (is_null($value) && !is_null($default)) {
                // Skip applying defaults during updates to avoid resetting unspecified attributes
                if (!$applyDefaults) {
                    continue;
                }
                $value = ($array) ? $default : [$default];
            } else {
                $value = ($array) ? $value : [$value];
            }

            if (!empty($filters)) {
                foreach ($value as $index => $node) {
                    if ($node !== null) {
                        foreach ($filters as $filter) {
                            $node = $this->encodeAttribute($filter, $node, $document);
                        }
                        $value[$index] = $node;
                    }
                }
            }

            if (!$array) {
                $value = $value[0];
            }
            $document->setAttribute($key, $value);
        }

        return $this->removeUnknownAttributes($collection, $document, $known);
    }

    /**
     * Decode Document
     *
     * @param Document $collection
     * @param Document $document
     * @param array<string> $selections
     * @return Document
     * @throws DatabaseException
     */
    public function decode(Document $collection, Document $document, array $selections = []): Document
    {
        $attributes = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute['type'] !== self::VAR_RELATIONSHIP
        );

        $relationships = \array_filter(
            $collection->getAttribute('attributes', []),
            fn ($attribute) => $attribute['type'] === self::VAR_RELATIONSHIP
        );

        $filteredValue = [];

        foreach ($relationships as $relationship) {
            $key = $relationship['$id'] ?? '';

            if (
                \array_key_exists($key, (array)$document)
                || \array_key_exists($this->adapter->filter($key), (array)$document)
            ) {
                $value = $document->getAttribute($key);
                $value ??= $document->getAttribute($this->adapter->filter($key));
                $document->removeAttribute($this->adapter->filter($key));
                $document->setAttribute($key, $value);
            }
        }

        $internalKeys = [];

        foreach ($this->getInternalAttributes() as $attribute) {
            $attributes[] = $attribute;
            $internalKeys[$attribute['$id']] = true;
        }

        $hasRelationshipSelections = false;
        foreach ($selections as $selection) {
            if (\str_contains($selection, '.')) {
                $hasRelationshipSelections = true;
                break;
            }
        }

        foreach ($attributes as $attribute) {
            $key = $attribute['$id'] ?? '';
            $type = $attribute['type'] ?? '';
            $array = $attribute['array'] ?? false;
            $filters = $attribute['filters'] ?? [];
            $value = $document->getAttribute($key);

            if ($key === '$permissions') {
                continue;
            }

            // filter() strips the leading "$" off an internal key, leaving a name a user
            // attribute is allowed to have ("$collection" -> "collection"). An internal value
            // never reaches the document under that name, so the alias lookup below has
            // nothing of its own to find and can only steal the user's attribute.
            if (\is_null($value) && !isset($internalKeys[$key])) {
                $filteredKey = $this->adapter->filter($key);
                $value = $document->getAttribute($filteredKey);

                if (!\is_null($value)) {
                    $document->removeAttribute($filteredKey);
                } elseif ($filteredKey !== $key && $document->offsetExists($filteredKey)) {
                    // SQL adapter column names use filter($key); remove the alias so the
                    // in-memory document only exposes keys (e.g. "a.b") that match the schema.
                    $document->removeAttribute($filteredKey);
                }
            }

            // Skip decoding for Operator objects (shouldn't happen, but safety check)
            if ($value instanceof Operator) {
                continue;
            }

            $value = ($array) ? $value : [$value];
            $value = (is_null($value)) ? [] : $value;

            $selected = empty($selections)
                || \in_array($key, $selections)
                || \in_array('*', $selections);

            if (!empty($filters) && ($selected || $hasRelationshipSelections)) {
                $filters = \array_reverse($filters);
                foreach ($value as $index => $node) {
                    foreach ($filters as $filter) {
                        $node = $this->decodeAttribute($filter, $node, $document, $key);
                    }
                    $value[$index] = $node;
                }
            }

            $filteredValue[$key] = ($array) ? $value : $value[0];

            if ($selected) {
                $document->setAttribute($key, ($array) ? $value : $value[0]);
            }
        }

        if ($hasRelationshipSelections && !empty($selections) && !\in_array('*', $selections)) {
            foreach ($collection->getAttribute('attributes', []) as $attribute) {
                $key = $attribute['$id'] ?? '';

                if ($attribute['type'] === self::VAR_RELATIONSHIP || $key === '$permissions') {
                    continue;
                }

                if (!in_array($key, $selections) && isset($filteredValue[$key])) {
                    $document->setAttribute($key, $filteredValue[$key]);
                }
            }
        }
        return $document;
    }

    /**
     * Casting
     *
     * @param Document $collection
     * @param Document $document
     *
     * @return Document
     */
    public function casting(Document $collection, Document $document): Document
    {
        if (!$this->adapter->getSupportForCasting()) {
            return $document;
        }

        $attributes = $collection->getAttribute('attributes', []);

        foreach ($this->getInternalAttributes() as $attribute) {
            $attributes[] = $attribute;
        }

        foreach ($attributes as $attribute) {
            $key = $attribute['$id'] ?? '';
            $type = $attribute['type'] ?? '';
            $signed = $attribute['signed'] ?? true;
            $array = $attribute['array'] ?? false;
            $value = $document->getAttribute($key, null);
            if (is_null($value)) {
                continue;
            }

            if ($key === '$permissions') {
                continue;
            }

            if ($array) {
                $value = !is_string($value)
                    ? $value
                    : json_decode($value, true);
            } else {
                $value = [$value];
            }

            if (\in_array($type, [self::VAR_ID, self::VAR_BOOLEAN, self::VAR_INTEGER, self::VAR_BIGINT, self::VAR_FLOAT], true)) {
                foreach ($value as $index => $node) {
                    switch ($type) {
                        case self::VAR_ID:
                            // Disabled until Appwrite migrates to use real int ID's for MySQL
                            //$type = $this->adapter->getIdAttributeType();
                            //\settype($node, $type);
                            $node = (string)$node;
                            break;
                        case self::VAR_BOOLEAN:
                            $node = (bool)$node;
                            break;
                        case self::VAR_INTEGER:
                            $node = (int)$node;
                            break;
                        case self::VAR_BIGINT:
                            if (\is_string($node) && BigIntValidator::fitsPhpInt($node, $signed)) {
                                $node = (int)$node;
                            }
                            break;
                        case self::VAR_FLOAT:
                            $node = (float)$node;
                            break;
                        default:
                            break;
                    }

                    $value[$index] = $node;
                }
            }

            $document->setAttribute($key, ($array) ? $value : $value[0]);
        }

        return $document;
    }

    /**
     * Encode Attribute
     *
     * Passes the attribute $value, and $document context to a predefined filter
     * that allow you to manipulate the input format of the given attribute.
     *
     * @param string $name
     * @param mixed $value
     * @param Document $document
     *
     * @return mixed
     * @throws DatabaseException
     */
    protected function encodeAttribute(string $name, mixed $value, Document $document): mixed
    {
        if (!array_key_exists($name, self::$filters) && !array_key_exists($name, $this->instanceFilters)) {
            throw new NotFoundException("Filter: {$name} not found");
        }

        try {
            if (\array_key_exists($name, $this->instanceFilters)) {
                $value = $this->instanceFilters[$name]['encode']($value, $document, $this);
            } else {
                $value = self::$filters[$name]['encode']($value, $document, $this);
            }
        } catch (\Throwable $th) {
            throw new DatabaseException($th->getMessage(), $th->getCode(), $th);
        }

        return $value;
    }

    /**
     * Decode Attribute
     *
     * Passes the attribute $value, and $document context to a predefined filter
     *  that allow you to manipulate the output format of the given attribute.
     *
     * @param string $filter
     * @param mixed $value
     * @param Document $document
     * @param string $attribute
     * @return mixed
     * @throws NotFoundException
     */
    protected function decodeAttribute(string $filter, mixed $value, Document $document, string $attribute): mixed
    {
        if (!$this->filter) {
            return $value;
        }

        if (!\is_null($this->disabledFilters) && isset($this->disabledFilters[$filter])) {
            return $value;
        }

        if (!array_key_exists($filter, self::$filters) && !array_key_exists($filter, $this->instanceFilters)) {
            throw new NotFoundException("Filter \"{$filter}\" not found for attribute \"{$attribute}\"");
        }

        if (array_key_exists($filter, $this->instanceFilters)) {
            $value = $this->instanceFilters[$filter]['decode']($value, $document, $this);
        } else {
            $value = self::$filters[$filter]['decode']($value, $document, $this);
        }

        return $value;
    }

    /**
     * Validate if a set of attributes can be selected from the collection
     *
     * @param Document $collection
     * @param array<Query> $queries
     * @return array<string>
     * @throws QueryException
     */
    private function validateSelections(Document $collection, array $queries): array
    {
        if (empty($queries)) {
            return [];
        }

        $selections = [];
        $relationshipSelections = [];

        foreach ($queries as $query) {
            if ($query->getMethod() == Query::TYPE_SELECT) {
                foreach ($query->getValues() as $value) {
                    if (!\is_string($value)) {
                        throw new QueryException('Attribute selection must be a string, got ' . \get_debug_type($value));
                    }
                    if (\str_contains($value, '.')) {
                        $relationshipSelections[] = $value;
                        continue;
                    }
                    $selections[] = $value;
                }
            }
        }

        // Allow querying internal attributes
        $keys = \array_map(
            fn ($attribute) => $attribute['$id'],
            $this->getInternalAttributes()
        );

        foreach ($collection->getAttribute('attributes', []) as $attribute) {
            if ($attribute['type'] !== self::VAR_RELATIONSHIP) {
                // Fallback to $id when key property is not present in metadata table for some tables such as Indexes or Attributes
                $keys[] = $attribute['key'] ?? $attribute['$id'];
            }
        }
        if ($this->adapter->getSupportForAttributes()) {
            $invalid = \array_diff($selections, $keys);
            if (!empty($invalid) && !\in_array('*', $invalid)) {
                throw new QueryException('Cannot select attributes: ' . \implode(', ', $invalid));
            }
        }

        $selections = \array_merge($selections, $relationshipSelections);

        $selections[] = '$id';
        $selections[] = '$sequence';
        $selections[] = '$collection';
        $selections[] = '$createdAt';
        $selections[] = '$updatedAt';
        $selections[] = '$permissions';

        return \array_values(\array_unique($selections));
    }

    /**
     * Get adapter attribute limit, accounting for internal metadata
     * Returns 0 to indicate no limit
     *
     * @return int
     */
    public function getLimitForAttributes(): int
    {
        if ($this->adapter->getLimitForAttributes() === 0) {
            return 0;
        }

        return $this->adapter->getLimitForAttributes() - $this->adapter->getCountOfDefaultAttributes();
    }

    /**
     * Get adapter index limit
     *
     * @return int
     */
    public function getLimitForIndexes(): int
    {
        return $this->adapter->getLimitForIndexes() - $this->adapter->getCountOfDefaultIndexes();
    }

    /**
     * @param Document $collection
     * @param array<Query> $queries
     * @return array<Query>
     * @throws QueryException
     * @throws \Utopia\Database\Exception
     */
    public function convertQueries(Document $collection, array $queries): array
    {
        foreach ($queries as $index => $query) {
            if ($query->isNested()) {
                $values = $this->convertQueries($collection, $query->getValues());
                $query->setValues($values);
            }

            $query = $this->convertQuery($collection, $query);

            $queries[$index] = $query;
        }

        return $queries;
    }

    /**
     * @param Document $collection
     * @param Query $query
     * @return Query
     * @throws QueryException
     * @throws \Utopia\Database\Exception
     */
    /**
     * Check if values are compatible with object attribute type (hashmap/multi-dimensional array)
     *
     * @param array<mixed> $values
     * @return bool
     */
    private function isCompatibleObjectValue(array $values): bool
    {
        if (empty($values)) {
            return false;
        }

        foreach ($values as $value) {
            if (!\is_array($value)) {
                return false;
            }

            // Check associative array (hashmap) or nested structure
            if (empty($value)) {
                continue;
            }

            // simple indexed array => not an object
            if (\array_keys($value) === \range(0, \count($value) - 1)) {
                return false;
            }

            foreach ($value as $nestedValue) {
                if (\is_array($nestedValue)) {
                    continue;
                }
            }
        }

        return true;
    }

    public function convertQuery(Document $collection, Query $query): Query
    {
        /**
         * @var array<Document> $attributes
         */
        $attributes = $collection->getAttribute('attributes', []);

        foreach (Database::INTERNAL_ATTRIBUTES as $attribute) {
            $attributes[] = new Document($attribute);
        }

        $queryAttribute = $query->getAttribute();
        $isNestedQueryAttribute = $this->getAdapter()->getSupportForAttributes() && $this->getAdapter()->getSupportForObject() && \str_contains($queryAttribute, '.');

        $attribute = new Document();

        foreach ($attributes as $attr) {
            if ($attr->getId() === $query->getAttribute()) {
                $attribute = $attr;
            } elseif ($isNestedQueryAttribute) {
                // nested object query
                $baseAttribute = \explode('.', $queryAttribute, 2)[0];
                if ($baseAttribute === $attr->getId() && $attr->getAttribute('type') === Database::VAR_OBJECT) {
                    $query->setAttributeType(Database::VAR_OBJECT);
                }
            }
        }

        if (!$attribute->isEmpty()) {
            $query->setOnArray($attribute->getAttribute('array', false));
            $query->setAttributeType($attribute->getAttribute('type'));

            if ($attribute->getAttribute('type') == Database::VAR_DATETIME) {
                $values = $query->getValues();
                foreach ($values as $valueIndex => $value) {
                    try {
                        $values[$valueIndex] = $this->adapter->getSupportForUTCCasting()
                            ? $this->adapter->setUTCDatetime($value)
                            : DateTime::setTimezone($value);
                    } catch (\Throwable $e) {
                        throw new QueryException($e->getMessage(), $e->getCode(), $e);
                    }
                }
                $query->setValues($values);
            }
        } elseif (!$this->adapter->getSupportForAttributes()) {
            $values = $query->getValues();
            // setting attribute type to properly apply filters in the adapter level
            if ($this->adapter->getSupportForObject() && $this->isCompatibleObjectValue($values)) {
                $query->setAttributeType(Database::VAR_OBJECT);
            }
        }

        return $query;
    }

    /**
     * @return  array<array<string, mixed>>
     */
    public function getInternalAttributes(): array
    {
        if ($this->adapter->getSharedTables()) {
            return self::INTERNAL_ATTRIBUTES;
        }

        return self::$tenantlessInternalAttributes ??= \array_values(\array_filter(
            self::INTERNAL_ATTRIBUTES,
            fn (array $attribute): bool => $attribute['$id'] !== '$tenant',
        ));
    }

    /**
     * Get Schema Attributes
     *
     * @param string $collection
     * @return array<Document>
     * @throws DatabaseException
     */
    public function getSchemaAttributes(string $collection): array
    {
        return $this->adapter->getSchemaAttributes($collection);
    }

    /**
     * @param string $collection
     * @return array<Document>
     */
    public function getSchemaIndexes(string $collection): array
    {
        return $this->adapter->getSchemaIndexes($collection);
    }

    /**
     * @param string $collectionId
     * @param string|null $documentId
     * @return array{0: string, 1: string}
     */
    public function getCacheBaseKeys(string $collectionId, ?string $documentId = null): array
    {
        if ($this->adapter->getSupportForHostname()) {
            $hostname = $this->adapter->getHostname();
        }

        $tenantSegment = $this->adapter->getTenant();

        if (
            $collectionId === self::METADATA &&
            $this->adapter->getSharedTables() &&
            isset($this->globalCollections[$documentId])
        ) {
            $tenantSegment = null;
        }

        $collectionKey = \sprintf(
            '%s-cache-%s:%s:%s:collection:%s',
            $this->cacheName,
            $hostname ?? '',
            $this->getNamespace(),
            $tenantSegment,
            $collectionId
        );

        return [$collectionKey, $documentId ? "{$collectionKey}:{$documentId}" : ''];
    }

    /**
     * @param string $collectionId
     * @param string|null $documentId
     * @param array<string> $selects
     * @return array{0: string, 1: string, 2: string}
     */
    public function getCacheKeys(string $collectionId, ?string $documentId = null, array $selects = []): array
    {
        [$collectionKey, $documentKey] = $this->getCacheBaseKeys($collectionId, $documentId);

        if ($documentId) {
            $sortedSelects = $selects;
            \sort($sortedSelects);

            $payload = ($this->resolveRelationships ? '1' : '0')
                . ':' . $this->getFilterSignatureKey()
                . ':' . ($sortedSelects === [] ? '' : (\json_encode($sortedSelects) ?: ''));

            $documentHashKey = $documentKey . ':' . \md5($payload);
        }

        return [
            $collectionKey,
            $documentKey,
            $documentHashKey ?? ''
        ];
    }

    /**
     * Stable cache key for cached query entries on a collection.
     *
     * @param string $collectionId
     * @param string|null $namespace
     * @return string
     */
    public function getQueryCacheKey(string $collectionId, ?string $namespace = null): string
    {
        $hostname = $this->adapter->getSupportForHostname()
            ? $this->adapter->getHostname()
            : '';

        return \sprintf(
            '%s-cache-%s:%s:%s:collection:%s:query',
            $this->cacheName,
            $hostname,
            $namespace ?? $this->getNamespace(),
            $this->adapter->getTenant(),
            $collectionId,
        );
    }

    /**
     * Stable cache field for cached query entries on a collection.
     *
     * @param Document|null $collection
     * @param array<Query> $queries
     * @param string $field
     * @param string $forPermission
     * @return string|null
     */
    public function getQueryCacheField(
        ?Document $collection = null,
        array $queries = [],
        string $field = 'documents',
        string $forPermission = self::PERMISSION_READ,
    ): ?string {
        $this->checkQueryTypes($queries);

        if ($forPermission !== self::PERMISSION_READ) {
            return null;
        }

        $authorizationRoles = \array_values(\array_unique($this->authorization->getRoles()));
        \sort($authorizationRoles);

        $queryPayload = [
            'version' => 1,
            'authorization' => [
                'enabled' => $this->authorization->getStatus(),
                'roles' => $authorizationRoles,
            ],
            'database' => $this->getDatabase(),
            'queries' => \array_map(
                fn (Query $query): array => $this->serializeQueryCacheQuery($query),
                $queries,
            ),
            'relationships' => $this->resolveRelationships,
            'filters' => $this->getActiveFilterSignatures(),
        ];

        $schemaHash = '';
        if ($collection !== null && !$collection->isEmpty()) {
            // Schema-affecting changes must move callers onto a fresh cache field.
            $schemaHash = \md5(
                \json_encode($collection->getAttribute('attributes', []))
                . \json_encode($collection->getAttribute('indexes', []))
                . \json_encode($collection->getAttribute('$permissions', []))
                . \json_encode($collection->getAttribute('documentSecurity', false))
            );
        }

        return \sprintf(
            '%s:%s:%s',
            $schemaHash,
            \md5(\json_encode($queryPayload) ?: ''),
            $field,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeQueryCacheQuery(Query $query): array
    {
        $serialized = [
            'method' => $query->getMethod(),
        ];

        if ($query->getAttribute() !== '') {
            $serialized['attribute'] = $query->getAttribute();
        }

        $values = [];
        foreach ($query->getValues() as $value) {
            if ($value instanceof Query) {
                $values[] = $this->serializeQueryCacheQuery($value);
                continue;
            }

            $values[] = $this->normalizeQueryCacheQueryValue($value);
        }

        $serialized['values'] = $values;

        return $serialized;
    }

    private function normalizeQueryCacheQueryValue(mixed $value): mixed
    {
        if ($value instanceof Document) {
            $value = $value->getArrayCopy();
        }

        if (!\is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->normalizeQueryCacheQueryValue($item);
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    private function getActiveFilterSignatures(): array
    {
        if (!$this->filter) {
            return [];
        }

        $this->refreshFilterSignatures();

        return $this->disabledFilters
            ? \array_diff_key($this->filterSignatures, $this->disabledFilters)
            : $this->filterSignatures;
    }

    private function refreshFilterSignatures(): void
    {
        if (
            $this->filterSignaturesVersion === self::$filtersVersion
            && $this->filterSignaturesSource === $this->instanceFilters
        ) {
            return;
        }

        $signatures = [];

        foreach (self::$filters as $name => $callbacks) {
            if (\array_key_exists($name, $this->instanceFilters)) {
                continue;
            }
            $signatures[$name] = $callbacks['signature'];
        }

        foreach ($this->instanceFilters as $name => $callbacks) {
            $signatures[$name] = $callbacks['signature'];
        }

        \ksort($signatures);

        $this->filterSignatures = $signatures;
        $this->filterSignaturesEncoded = \json_encode($signatures) ?: '';
        $this->filterSignaturesVersion = self::$filtersVersion;
        $this->filterSignaturesSource = $this->instanceFilters;
    }

    private function getFilterSignatureKey(): string
    {
        if (!$this->filter) {
            return '';
        }

        if ($this->disabledFilters) {
            return \json_encode($this->getActiveFilterSignatures()) ?: '';
        }

        $this->refreshFilterSignatures();

        return $this->filterSignaturesEncoded;
    }

    private static function computeCallableSignature(callable $callable): string
    {
        if (\is_string($callable)) {
            return $callable;
        }

        if (\is_array($callable)) {
            $class = \is_object($callable[0]) ? \get_class($callable[0]) : $callable[0];
            return $class . '::' . $callable[1];
        }

        $closure = \Closure::fromCallable($callable);
        $ref = new \ReflectionFunction($closure);
        return ($ref->getFileName() ?: 'unknown') . ':' . $ref->getStartLine();
    }

    /**
     * @param array<Query> $queries
     * @return void
     * @throws QueryException
     */
    private function checkQueryTypes(array $queries): void
    {
        foreach ($queries as $query) {
            if (!$query instanceof Query) {
                throw new QueryException('Invalid query type: "' . \gettype($query) . '". Expected instances of "' . Query::class . '"');
            }

            if ($query->isNested()) {
                $this->checkQueryTypes($query->getValues());
            }
        }
    }

    /**
     * Process relationship queries, extracting nested selections.
     *
     * @param array<Document> $relationships
     * @param array<Query> $queries
     * @return array<string, array<Query>> $selects
     */
    private function processRelationshipQueries(
        array $relationships,
        array $queries,
    ): array {
        $nestedSelections = [];

        foreach ($queries as $query) {
            if ($query->getMethod() !== Query::TYPE_SELECT) {
                continue;
            }

            $values = $query->getValues();
            foreach ($values as $valueIndex => $value) {
                if (!\is_string($value) || !\str_contains($value, '.')) {
                    continue;
                }

                $nesting = \explode('.', $value);
                $selectedKey = \array_shift($nesting); // Remove and return first item

                $relationship = \array_values(\array_filter(
                    $relationships,
                    fn (Document $relationship) => $relationship->getAttribute('key') === $selectedKey,
                ))[0] ?? null;

                if (!$relationship) {
                    continue;
                }

                // Shift the top level off the dot-path to pass the selection down the chain
                // 'foo.bar.baz' becomes 'bar.baz'

                $nestingPath = \implode('.', $nesting);

                // If nestingPath is empty, it means we want all attributes (*) for this relationship
                if (empty($nestingPath)) {
                    $nestedSelections[$selectedKey][] = Query::select(['*']);
                } else {
                    $nestedSelections[$selectedKey][] = Query::select([$nestingPath]);
                }

                $type = $relationship->getAttribute('options')['relationType'];
                $side = $relationship->getAttribute('options')['side'];

                switch ($type) {
                    case Database::RELATION_MANY_TO_MANY:
                        unset($values[$valueIndex]);
                        break;
                    case Database::RELATION_ONE_TO_MANY:
                        if ($side === Database::RELATION_SIDE_PARENT) {
                            unset($values[$valueIndex]);
                        } else {
                            $values[$valueIndex] = $selectedKey;
                        }
                        break;
                    case Database::RELATION_MANY_TO_ONE:
                        if ($side === Database::RELATION_SIDE_PARENT) {
                            $values[$valueIndex] = $selectedKey;
                        } else {
                            unset($values[$valueIndex]);
                        }
                        break;
                    case Database::RELATION_ONE_TO_ONE:
                        $values[$valueIndex] = $selectedKey;
                        break;
                }
            }

            $finalValues = \array_values($values);
            if ($query->getMethod() === Query::TYPE_SELECT) {
                if (empty($finalValues)) {
                    $finalValues = ['*'];
                }
            }
            $query->setValues($finalValues);
        }

        return $nestedSelections;
    }

    /**
     * Process nested relationship path iteratively
     *
     * Instead of recursive calls, this method processes multi-level queries in a single loop
     * working from the deepest level up to minimize database queries.
     *
     * Example: For "project.employee.company.name":
     * 1. Query companies matching name filter -> IDs [c1, c2]
     * 2. Query employees with company IN [c1, c2] -> IDs [e1, e2, e3]
     * 3. Query projects with employee IN [e1, e2, e3] -> IDs [p1, p2]
     * 4. Return [p1, p2]
     *
     * @param string $startCollection The starting collection for the path
     * @param array<Query> $queries Queries with nested paths
     * @return array<string>|null Array of matching IDs or null if no matches
     */
    private function processNestedRelationshipPath(string $startCollection, array $queries): ?array
    {
        // Build a map of all nested paths and their queries
        $pathGroups = [];
        foreach ($queries as $query) {
            $attribute = $query->getAttribute();
            if (\str_contains($attribute, '.')) {
                $parts = \explode('.', $attribute);
                $pathKey = \implode('.', \array_slice($parts, 0, -1)); // Everything except the last part
                if (!isset($pathGroups[$pathKey])) {
                    $pathGroups[$pathKey] = [];
                }
                $pathGroups[$pathKey][] = [
                    'method' => $query->getMethod(),
                    'attribute' => \end($parts), // The actual attribute to query
                    'values' => $query->getValues(),
                ];
            }
        }

        $allMatchingIds = [];
        foreach ($pathGroups as $path => $queryGroup) {
            $pathParts = \explode('.', $path);
            $currentCollection = $startCollection;
            $relationshipChain = [];

            foreach ($pathParts as $relationshipKey) {
                $collectionDoc = $this->silent(fn () => $this->getCollection($currentCollection));
                $relationships = \array_filter(
                    $collectionDoc->getAttribute('attributes', []),
                    fn ($attr) => $attr['type'] === self::VAR_RELATIONSHIP
                );

                $relationship = null;
                foreach ($relationships as $rel) {
                    if ($rel['key'] === $relationshipKey) {
                        $relationship = $rel;
                        break;
                    }
                }

                if (!$relationship) {
                    return null;
                }

                $relationshipChain[] = [
                    'key' => $relationshipKey,
                    'fromCollection' => $currentCollection,
                    'toCollection' => $relationship['options']['relatedCollection'],
                    'relationType' => $relationship['options']['relationType'],
                    'side' => $relationship['options']['side'],
                    'twoWayKey' => $relationship['options']['twoWayKey'],
                ];

                $currentCollection = $relationship['options']['relatedCollection'];
            }

            // Now walk backwards from the deepest collection to the starting collection
            $leafQueries = [];
            foreach ($queryGroup as $q) {
                $leafQueries[] = new Query($q['method'], $q['attribute'], $q['values']);
            }

            // Query the deepest collection
            $matchingDocs = $this->silent(fn () => $this->skipRelationships(fn () => $this->find(
                $currentCollection,
                \array_merge($leafQueries, [
                    Query::select(['$id']),
                    Query::limit(PHP_INT_MAX),
                ])
            )));

            $matchingIds = \array_map(fn ($doc) => $doc->getId(), $matchingDocs);

            if (empty($matchingIds)) {
                return null;
            }

            // Walk back up the chain
            for ($i = \count($relationshipChain) - 1; $i >= 0; $i--) {
                $link = $relationshipChain[$i];
                $relationType = $link['relationType'];
                $side = $link['side'];

                // Determine how to query the parent collection
                $needsReverseLookup = (
                    ($relationType === self::RELATION_ONE_TO_MANY && $side === self::RELATION_SIDE_PARENT) ||
                    ($relationType === self::RELATION_MANY_TO_ONE && $side === self::RELATION_SIDE_CHILD) ||
                    ($relationType === self::RELATION_MANY_TO_MANY)
                );

                if ($needsReverseLookup) {
                    if ($relationType === self::RELATION_MANY_TO_MANY) {
                        // For many-to-many, query the junction table directly instead
                        // of resolving full relationships on the child documents.
                        $fromCollectionDoc = $this->silent(fn () => $this->getCollection($link['fromCollection']));
                        $toCollectionDoc = $this->silent(fn () => $this->getCollection($link['toCollection']));
                        $junction = $this->getJunctionCollection($fromCollectionDoc, $toCollectionDoc, $link['side']);

                        $junctionDocs = $this->silent(fn () => $this->skipRelationships(fn () => $this->find($junction, [
                            Query::equal($link['key'], $matchingIds),
                            Query::limit(PHP_INT_MAX),
                        ])));

                        $parentIds = [];
                        foreach ($junctionDocs as $jDoc) {
                            $pId = $jDoc->getAttribute($link['twoWayKey']);
                            if ($pId && !\in_array($pId, $parentIds)) {
                                $parentIds[] = $pId;
                            }
                        }
                    } else {
                        // Need to find parents by querying children and extracting parent IDs
                        $childDocs = $this->silent(fn () => $this->skipRelationships(fn () => $this->find(
                            $link['toCollection'],
                            [
                                Query::equal('$id', $matchingIds),
                                Query::select(['$id', $link['twoWayKey']]),
                                Query::limit(PHP_INT_MAX),
                            ]
                        )));

                        $parentIds = [];
                        foreach ($childDocs as $doc) {
                            $parentValue = $doc->getAttribute($link['twoWayKey']);
                            if (\is_array($parentValue)) {
                                foreach ($parentValue as $pId) {
                                    if ($pId instanceof Document) {
                                        $pId = $pId->getId();
                                    }
                                    if ($pId && !\in_array($pId, $parentIds)) {
                                        $parentIds[] = $pId;
                                    }
                                }
                            } else {
                                if ($parentValue instanceof Document) {
                                    $parentValue = $parentValue->getId();
                                }
                                if ($parentValue && !\in_array($parentValue, $parentIds)) {
                                    $parentIds[] = $parentValue;
                                }
                            }
                        }
                    }
                    $matchingIds = $parentIds;
                } else {
                    // Can directly filter parent by the relationship key
                    $parentDocs = $this->silent(fn () => $this->skipRelationships(fn () => $this->find(
                        $link['fromCollection'],
                        [
                            Query::equal($link['key'], $matchingIds),
                            Query::select(['$id']),
                            Query::limit(PHP_INT_MAX),
                        ]
                    )));
                    $matchingIds = \array_map(fn ($doc) => $doc->getId(), $parentDocs);
                }

                if (empty($matchingIds)) {
                    return null;
                }
            }

            $allMatchingIds = \array_merge($allMatchingIds, $matchingIds);
        }

        return \array_unique($allMatchingIds);
    }

    /**
     * Convert relationship queries to SQL-safe subqueries recursively
     *
     * Queries like Query::equal('author.name', ['Alice']) are converted to
     * Query::equal('author', [<matching author IDs>])
     *
     * This method supports multi-level nested relationship queries:
     * - Depth 1: employee.name
     * - Depth 2: employee.company.name
     * - Depth 3: project.employee.company.name
     *
     * The method works by:
     * 1. Parsing dot-path queries (e.g., "project.employee.company.name")
     * 2. Extracting the first relationship (e.g., "project")
     * 3. If the nested attribute still contains dots, using iterative processing
     * 4. Finding matching documents in the related collection
     * 5. Converting to filters on the parent collection
     *
     * @param array<Document> $relationships
     * @param array<Query> $queries
     * @return array<Query>|null Returns null if relationship filters cannot match any documents
     */
    private function convertRelationshipQueries(
        array $relationships,
        array $queries,
        ?Document $collection = null,
    ): ?array {
        // Early return if no relationship queries exist
        $hasRelationshipQuery = false;
        foreach ($queries as $query) {
            $attr = $query->getAttribute();
            if (\str_contains($attr, '.') || $query->getMethod() === Query::TYPE_CONTAINS_ALL) {
                $hasRelationshipQuery = true;
                break;
            }
        }

        if (!$hasRelationshipQuery) {
            return $queries;
        }

        $relationshipsByKey = [];
        foreach ($relationships as $relationship) {
            $relationshipsByKey[$relationship->getAttribute('key')] = $relationship;
        }

        $additionalQueries = [];
        $groupedQueries = [];
        $indicesToRemove = [];

        // Handle containsAll queries first
        foreach ($queries as $index => $query) {
            if ($query->getMethod() !== Query::TYPE_CONTAINS_ALL) {
                continue;
            }

            $attribute = $query->getAttribute();

            if (!\str_contains($attribute, '.')) {
                continue; // Non-relationship containsAll handled by adapter
            }

            $parts = \explode('.', $attribute);
            $relationshipKey = \array_shift($parts);
            $nestedAttribute = \implode('.', $parts);
            $relationship = $relationshipsByKey[$relationshipKey] ?? null;

            if (!$relationship) {
                continue;
            }

            // Resolve each value independently, then intersect parent IDs
            $parentIdSets = [];
            $resolvedAttribute = '$id';
            foreach ($query->getValues() as $value) {
                $relatedQuery = Query::equal($nestedAttribute, [$value]);
                $result = $this->resolveRelationshipGroupToIds($relationship, [$relatedQuery], $collection);

                if ($result === null) {
                    return null;
                }

                $resolvedAttribute = $result['attribute'];
                $parentIdSets[] = $result['ids'];
            }

            $ids = \count($parentIdSets) > 1
                ? \array_values(\array_intersect(...$parentIdSets))
                : ($parentIdSets[0] ?? []);

            if (empty($ids)) {
                return null;
            }

            $additionalQueries[] = Query::equal($resolvedAttribute, $ids);
            $indicesToRemove[] = $index;
        }

        // Group regular dot-path queries by relationship key
        foreach ($queries as $index => $query) {
            if ($query->getMethod() === Query::TYPE_SELECT || $query->getMethod() === Query::TYPE_CONTAINS_ALL) {
                continue;
            }

            $attribute = $query->getAttribute();

            if (!\str_contains($attribute, '.')) {
                continue;
            }

            $parts = \explode('.', $attribute);
            $relationshipKey = \array_shift($parts);
            $nestedAttribute = \implode('.', $parts);
            $relationship = $relationshipsByKey[$relationshipKey] ?? null;

            if (!$relationship) {
                continue;
            }

            if (!isset($groupedQueries[$relationshipKey])) {
                $groupedQueries[$relationshipKey] = [
                    'relationship' => $relationship,
                    'queries' => [],
                    'indices' => []
                ];
            }

            $groupedQueries[$relationshipKey]['queries'][] = [
                'method' => $query->getMethod(),
                'attribute' => $nestedAttribute,
                'values' => $query->getValues()
            ];

            $groupedQueries[$relationshipKey]['indices'][] = $index;
        }

        // Process each relationship group
        foreach ($groupedQueries as $relationshipKey => $group) {
            $relationship = $group['relationship'];

            // Detect impossible conditions: multiple equal on same attribute
            $equalAttrs = [];
            foreach ($group['queries'] as $queryData) {
                if ($queryData['method'] === Query::TYPE_EQUAL) {
                    $attr = $queryData['attribute'];
                    if (isset($equalAttrs[$attr])) {
                        throw new QueryException("Multiple equal queries on '{$relationshipKey}.{$attr}' will never match a single document. Use Query::containsAll() to match across different related documents.");
                    }
                    $equalAttrs[$attr] = true;
                }
            }

            $relatedQueries = [];
            foreach ($group['queries'] as $queryData) {
                $relatedQueries[] = new Query(
                    $queryData['method'],
                    $queryData['attribute'],
                    $queryData['values']
                );
            }

            try {
                $result = $this->resolveRelationshipGroupToIds($relationship, $relatedQueries, $collection);

                if ($result === null) {
                    return null;
                }

                $additionalQueries[] = Query::equal($result['attribute'], $result['ids']);

                foreach ($group['indices'] as $originalIndex) {
                    $indicesToRemove[] = $originalIndex;
                }
            } catch (QueryException $e) {
                throw $e;
            } catch (\Exception $e) {
                return null;
            }
        }

        // Remove the original queries
        foreach ($indicesToRemove as $index) {
            unset($queries[$index]);
        }

        // Merge additional queries
        return \array_merge(\array_values($queries), $additionalQueries);
    }

    /**
     * Resolve a group of relationship queries to matching document IDs.
     *
     * @param Document $relationship
     * @param array<Query> $relatedQueries Queries on the related collection
     * @param Document|null $collection The parent collection document (needed for junction table lookups)
     * @return array{attribute: string, ids: string[]}|null
     */
    private function resolveRelationshipGroupToIds(
        Document $relationship,
        array $relatedQueries,
        ?Document $collection = null,
    ): ?array {
        $relatedCollection = $relationship->getAttribute('options')['relatedCollection'];
        $relationType = $relationship->getAttribute('options')['relationType'];
        $side = $relationship->getAttribute('options')['side'];
        $relationshipKey = $relationship->getAttribute('key');

        // Process multi-level queries by walking the relationship chain
        $hasNestedPaths = false;
        foreach ($relatedQueries as $relatedQuery) {
            if (\str_contains($relatedQuery->getAttribute(), '.')) {
                $hasNestedPaths = true;
                break;
            }
        }

        if ($hasNestedPaths) {
            $matchingIds = $this->processNestedRelationshipPath(
                $relatedCollection,
                $relatedQueries
            );

            if ($matchingIds === null || empty($matchingIds)) {
                return null;
            }

            $relatedQueries = \array_values(\array_merge(
                \array_filter($relatedQueries, fn (Query $q) => !\str_contains($q->getAttribute(), '.')),
                [Query::equal('$id', $matchingIds)]
            ));
        }

        $needsParentResolution = (
            ($relationType === self::RELATION_ONE_TO_MANY && $side === self::RELATION_SIDE_PARENT) ||
            ($relationType === self::RELATION_MANY_TO_ONE && $side === self::RELATION_SIDE_CHILD) ||
            ($relationType === self::RELATION_MANY_TO_MANY)
        );

        if ($relationType === self::RELATION_MANY_TO_MANY && $needsParentResolution && $collection !== null) {
            // For many-to-many, query the junction table directly instead of relying
            // on relationship population (which fails when resolveRelationships is false,
            // e.g. when the outer find() is wrapped in skipRelationships()).
            $matchingDocs = $this->silent(fn () => $this->skipRelationships(fn () => $this->find(
                $relatedCollection,
                \array_merge($relatedQueries, [
                    Query::select(['$id']),
                    Query::limit(PHP_INT_MAX),
                ])
            )));

            $matchingIds = \array_map(fn ($doc) => $doc->getId(), $matchingDocs);

            if (empty($matchingIds)) {
                return null;
            }

            $twoWayKey = $relationship->getAttribute('options')['twoWayKey'];
            $relatedCollectionDoc = $this->silent(fn () => $this->getCollection($relatedCollection));
            $junction = $this->getJunctionCollection($collection, $relatedCollectionDoc, $side);

            $junctionDocs = $this->silent(fn () => $this->skipRelationships(fn () => $this->find($junction, [
                Query::equal($relationshipKey, $matchingIds),
                Query::limit(PHP_INT_MAX),
            ])));

            $parentIds = [];
            foreach ($junctionDocs as $jDoc) {
                $pId = $jDoc->getAttribute($twoWayKey);
                if ($pId && !\in_array($pId, $parentIds)) {
                    $parentIds[] = $pId;
                }
            }

            return empty($parentIds) ? null : ['attribute' => '$id', 'ids' => $parentIds];
        } elseif ($needsParentResolution) {
            // For one-to-many/many-to-one parent resolution, we need relationship
            // population to read the twoWayKey attribute from the related documents.
            $matchingDocs = $this->silent(fn () => $this->find(
                $relatedCollection,
                \array_merge($relatedQueries, [
                    Query::limit(PHP_INT_MAX),
                ])
            ));

            $twoWayKey = $relationship->getAttribute('options')['twoWayKey'];
            $parentIds = [];

            foreach ($matchingDocs as $doc) {
                $parentId = $doc->getAttribute($twoWayKey);

                if (\is_array($parentId)) {
                    foreach ($parentId as $id) {
                        if ($id instanceof Document) {
                            $id = $id->getId();
                        }
                        if ($id && !\in_array($id, $parentIds)) {
                            $parentIds[] = $id;
                        }
                    }
                } else {
                    if ($parentId instanceof Document) {
                        $parentId = $parentId->getId();
                    }
                    if ($parentId && !\in_array($parentId, $parentIds)) {
                        $parentIds[] = $parentId;
                    }
                }
            }

            return empty($parentIds) ? null : ['attribute' => '$id', 'ids' => $parentIds];
        } else {
            $matchingDocs = $this->silent(fn () => $this->skipRelationships(fn () => $this->find(
                $relatedCollection,
                \array_merge($relatedQueries, [
                    Query::select(['$id']),
                    Query::limit(PHP_INT_MAX),
                ])
            )));

            $matchingIds = \array_map(fn ($doc) => $doc->getId(), $matchingDocs);
            return empty($matchingIds) ? null : ['attribute' => $relationshipKey, 'ids' => $matchingIds];
        }
    }

    /**
     * Encode spatial data from array format to WKT (Well-Known Text) format
     *
     * @param mixed $value
     * @param string $type
     * @return string
     * @throws DatabaseException
     */
    protected function encodeSpatialData(mixed $value, string $type): string
    {
        $validator = new Spatial($type);
        if (!$validator->isValid($value)) {
            throw new StructureException($validator->getDescription());
        }

        switch ($type) {
            case self::VAR_POINT:
                return "POINT({$value[0]} {$value[1]})";

            case self::VAR_LINESTRING:
                $points = [];
                foreach ($value as $point) {
                    $points[] = "{$point[0]} {$point[1]}";
                }
                return 'LINESTRING(' . implode(', ', $points) . ')';

            case self::VAR_POLYGON:
                // Check if this is a single ring (flat array of points) or multiple rings
                $isSingleRing = count($value) > 0 && is_array($value[0]) &&
                    count($value[0]) === 2 && is_numeric($value[0][0]) && is_numeric($value[0][1]);

                if ($isSingleRing) {
                    // Convert single ring format [[x1,y1], [x2,y2], ...] to multi-ring format
                    $value = [$value];
                }

                $rings = [];
                foreach ($value as $ring) {
                    $points = [];
                    foreach ($ring as $point) {
                        $points[] = "{$point[0]} {$point[1]}";
                    }
                    $rings[] = '(' . implode(', ', $points) . ')';
                }
                return 'POLYGON(' . implode(', ', $rings) . ')';

            default:
                throw new DatabaseException('Unknown spatial type: ' . $type);
        }
    }

    /**
     * Retry a callable with exponential backoff
     *
     * @param callable $operation The operation to retry
     * @param int $maxAttempts Maximum number of retry attempts
     * @param int $initialDelayMs Initial delay in milliseconds
     * @param float $multiplier Backoff multiplier
     * @return void The result of the operation
     * @throws \Throwable The last exception if all retries fail
     */
    private function withRetries(
        callable $operation,
        int $maxAttempts = 3,
        int $initialDelayMs = 100,
        float $multiplier = 2.0
    ): void {
        $attempt = 0;
        $delayMs = $initialDelayMs;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            try {
                $operation();
                return;
            } catch (\Throwable $e) {
                $lastException = $e;
                $attempt++;

                if ($attempt >= $maxAttempts) {
                    break;
                }

                if (\extension_loaded('swoole') && Coroutine::getCid() > 0) {
                    Coroutine::sleep($delayMs / 1000);
                } else {
                    \usleep($delayMs * 1000);
                }

                $delayMs = (int)($delayMs * $multiplier);
            }
        }

        throw $lastException;
    }

    /**
     * Generic cleanup operation with retry logic
     *
     * @param callable $operation The cleanup operation to execute
     * @param string $resourceType Type of resource being cleaned up (e.g., 'attribute', 'index')
     * @param string $resourceId ID of the resource being cleaned up
     * @param int $maxAttempts Maximum retry attempts
     * @return void
     * @throws DatabaseException If cleanup fails after all retries
     */
    private function cleanup(
        callable $operation,
        string $resourceType,
        string $resourceId,
        int $maxAttempts = 3
    ): void {
        try {
            $this->withRetries($operation, maxAttempts: $maxAttempts);
        } catch (\Throwable $e) {
            Console::error("Failed to cleanup {$resourceType} '{$resourceId}' after {$maxAttempts} attempts: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Cleanup (delete) an index with retry logic
     *
     * @param string $collectionId The collection ID
     * @param string $indexId The index ID
     * @param int $maxAttempts Maximum retry attempts
     * @return void
     * @throws DatabaseException If cleanup fails after all retries
     */
    private function cleanupIndex(
        string $collectionId,
        string $indexId,
        int $maxAttempts = 3
    ): void {
        $this->cleanup(
            fn () => $this->adapter->deleteIndex($collectionId, $indexId),
            'index',
            $indexId,
            $maxAttempts
        );
    }

    /**
     * Persist metadata with automatic rollback on failure
     *
     * Centralizes the common pattern of:
     * 1. Attempting to persist metadata with retry
     * 2. Rolling back database operations if metadata persistence fails
     * 3. Providing detailed error messages for both success and failure scenarios
     *
     * @param Document $collection The collection document to persist
     * @param callable|null $rollbackOperation Cleanup operation to run if persistence fails (null if no cleanup needed)
     * @param bool $shouldRollback Whether rollback should be attempted (e.g., false for duplicates in shared tables)
     * @param string $operationDescription Description of the operation for error messages
     * @param bool $rollbackReturnsErrors Whether rollback operation returns error array (true) or throws (false)
     * @param bool $silentRollback Whether rollback errors should be silently caught (true) or thrown (false)
     * @return void
     * @throws DatabaseException If metadata persistence fails after all retries
     */
    private function updateMetadata(
        Document $collection,
        ?callable $rollbackOperation,
        bool $shouldRollback,
        string $operationDescription = 'operation',
        bool $rollbackReturnsErrors = false,
        bool $silentRollback = false
    ): void {
        try {
            if ($collection->getId() !== self::METADATA) {
                $this->withRetries(
                    fn () => $this->silent(fn () => $this->updateDocument(self::METADATA, $collection->getId(), $collection))
                );
            }
        } catch (\Throwable $e) {
            // Attempt rollback only if conditions are met
            if ($shouldRollback && $rollbackOperation !== null) {
                if ($rollbackReturnsErrors) {
                    // Batch mode: rollback returns array of errors
                    $cleanupErrors = $rollbackOperation();
                    if (!empty($cleanupErrors)) {
                        throw new DatabaseException(
                            "Failed to persist metadata after retries and cleanup encountered errors for {$operationDescription}: " . $e->getMessage() . ' | Cleanup errors: ' . implode(', ', $cleanupErrors),
                            previous: $e
                        );
                    }
                } elseif ($silentRollback) {
                    // Silent mode: swallow rollback errors
                    try {
                        $rollbackOperation();
                    } catch (\Throwable $e) {
                        // Silent rollback - errors are swallowed
                    }
                } else {
                    // Regular mode: rollback throws on failure
                    try {
                        $rollbackOperation();
                    } catch (\Throwable $ex) {
                        throw new DatabaseException(
                            "Failed to persist metadata after retries and cleanup failed for {$operationDescription}: " . $ex->getMessage() . ' | Cleanup error: ' . $e->getMessage(),
                            previous: $e
                        );
                    }
                }
            }

            throw new DatabaseException(
                "Failed to persist metadata after retries for {$operationDescription}: " . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * Rollback metadata state by removing specified attributes from collection
     *
     * @param Document $collection The collection document
     * @param array<string> $attributeIds Attribute IDs to remove
     * @return void
     */
    private function rollbackAttributeMetadata(Document $collection, array $attributeIds): void
    {
        $attributes = $collection->getAttribute('attributes', []);
        $filteredAttributes = \array_filter(
            $attributes,
            fn ($attr) => !\in_array($attr->getId(), $attributeIds)
        );
        $collection->setAttribute('attributes', \array_values($filteredAttributes));
    }
}
