<?php

namespace Utopia\Database\Adapter;

use Exception;
use PDO;
use PDOException;
use Utopia\Database\Database;
use Utopia\Database\Document;
use Utopia\Database\Exception as DatabaseException;
use Utopia\Database\Exception\Duplicate as DuplicateException;
use Utopia\Database\Exception\Limit as LimitException;
use Utopia\Database\Exception\NotFound as NotFoundException;
use Utopia\Database\Exception\Operator as OperatorException;
use Utopia\Database\Exception\Timeout as TimeoutException;
use Utopia\Database\Exception\Transaction as TransactionException;
use Utopia\Database\Exception\Truncate as TruncateException;
use Utopia\Database\Exception\Unique as UniqueException;
use Utopia\Database\Helpers\ID;
use Utopia\Database\Operator;
use Utopia\Database\Query;

/**
 * Main differences from MariaDB and MySQL:
 *
 * 1. No concept of a schema. All tables are in the same schema.
 * 2. AUTO_INCREMENT is AUTOINCREAMENT.
 * 3. Can't create indexes in the same statement as creating a table.
 * 4. Can't use SET to bind values on INSERT
 * 5. last_insert_id is last_insert_rowid
 * 6. Can only drop one table at a time
 * 7. no index length support?
 * 8. DELETE doesn't support ORDER BY or LIMIT
 * 9. MODIFY COLUMN is not supported
 * 10. Can't rename an index directly
 */
class SQLite extends MariaDB
{
    /** Suffix appended to every FTS5 virtual table name created by this adapter. */
    private const FTS_TABLE_SUFFIX = '_fts';

    /** AFTER INSERT trigger suffix on the parent collection. */
    private const FTS_TRIGGER_INSERT = 'ai';

    /** AFTER DELETE trigger suffix on the parent collection. */
    private const FTS_TRIGGER_DELETE = 'ad';

    /** AFTER UPDATE trigger suffix on the parent collection. */
    private const FTS_TRIGGER_UPDATE = 'au';

    /**
     * Per-collection attribute → FTS5 table memo. Populated in one pass
     * so multi-attribute SEARCH batches don't issue PRAGMA per attribute.
     *
     * @var array<string, array<string, ?string>>
     */
    private array $ftsTableCache = [];

    /**
     * When enabled, the adapter reports MariaDB-shaped column metadata,
     * advertises MariaDB-only capabilities (upserts, attribute resizing,
     * PCRE regex via the registered UDF), and declares schema-internal
     * columns (e.g. `_tenant`) using MariaDB-style types so callers that
     * inspect INFORMATION_SCHEMA-style results behave identically across
     * both adapters. Off by default — vanilla SQLite stays vanilla.
     */
    protected bool $emulateMySQL = false;

    /**
     * Whether the REGEXP UDF actually wired up. Pool/proxy PDOs may not
     * expose sqliteCreateFunction.
     */
    private bool $pcreRegistered = false;

    public function __construct(mixed $pdo)
    {
        parent::__construct($pdo);

        $this->registerUserFunctions();
    }

    /**
     * Toggle MariaDB/MySQL emulation. See $emulateMySQL for what this
     * actually changes.
     */
    public function setEmulateMySQL(bool $emulate): static
    {
        $this->emulateMySQL = $emulate;

        return $this;
    }

    public function getEmulateMySQL(): bool
    {
        return $this->emulateMySQL;
    }

    public function setTenant(int|string|null $tenant): bool
    {
        $changed = $this->tenant !== $tenant;
        $result = parent::setTenant($tenant);
        if ($changed) {
            // Invalidate after the parent setter so a validation failure
            // doesn't leave us with a cleared cache against the prior tenant.
            $this->ftsTableCache = [];
        }

        return $result;
    }

    public function setNamespace(string $namespace): static
    {
        // Invalidate after the parent setter so a thrown validation
        // doesn't leave a cleared cache against the prior namespace.
        parent::setNamespace($namespace);
        $this->ftsTableCache = [];

        return $this;
    }

    public function setSharedTables(bool $sharedTables): bool
    {
        $changed = $this->sharedTables !== $sharedTables;
        $result = parent::setSharedTables($sharedTables);
        if ($changed) {
            $this->ftsTableCache = [];
        }

        return $result;
    }

    /**
     * Reject patterns over this size to bound ReDoS exposure — the UDF runs
     * once per candidate row, so a pathological pattern is amplified by
     * table cardinality.
     */
    private const REGEXP_MAX_PATTERN_LENGTH = 512;

    /**
     * Cap on cached delimited patterns. Long-lived adapters processing many
     * distinct user patterns would otherwise grow this map without bound.
     */
    private const REGEXP_PATTERN_CACHE_LIMIT = 256;

    /**
     * Register a preg_match-backed REGEXP UDF so the inherited REGEXP
     * path resolves. Best-effort — non-SQLite PDOs simply skip it.
     */
    private function registerUserFunctions(): void
    {
        // SQLite invokes the UDF once per candidate row, so cache the
        // delimited pattern across rows. FIFO-evict at REGEXP_PATTERN_CACHE_LIMIT
        // entries so distinct user patterns can't grow this without bound.
        $delimitedCache = [];
        $pcre = static function (?string $pattern, ?string $value) use (&$delimitedCache): int {
            if ($pattern === null || $value === null) {
                return 0;
            }
            if (\strlen($pattern) > self::REGEXP_MAX_PATTERN_LENGTH) {
                return 0;
            }

            if (!isset($delimitedCache[$pattern])) {
                if (\count($delimitedCache) >= self::REGEXP_PATTERN_CACHE_LIMIT) {
                    \array_shift($delimitedCache);
                }
                // Use a delimiter unlikely to appear in user input (chr(1))
                // so we don't have to escape forward-slashes the user wrote
                // intentionally and risk double-escaping backslashes.
                $delimitedCache[$pattern] = "\x01" . $pattern . "\x01u";
            }
            $delimited = $delimitedCache[$pattern];

            // preg_match returns false on bad pattern / runtime error
            // (e.g. PREG_BACKTRACK_LIMIT_ERROR). Silently treat those as
            // no-match — REGEXP is a query predicate, not a validator.
            $result = @\preg_match($delimited, $value);

            return $result === 1 ? 1 : 0;
        };

        try {
            $this->getPDO()->sqliteCreateFunction('REGEXP', $pcre, 2);
            $this->pcreRegistered = true;
        } catch (\Throwable) {
        }
    }

    /**
     * @inheritDoc
     *
     * SQLite serialises writers through a single file lock. PDO's default
     * `BEGIN` is `DEFERRED`, which acquires the writer lock lazily on the
     * first write — if two transactions both started as readers and try to
     * promote to writer at the same time, one fails immediately with
     * SQLITE_BUSY without any busy_timeout retry (a real deadlock case).
     * `BEGIN IMMEDIATE` reserves the writer slot up-front so concurrent
     * writers queue behind it under busy_timeout instead.
     */
    public function startTransaction(): bool
    {
        try {
            if ($this->inTransaction === 0) {
                if ($this->getPDO()->inTransaction()) {
                    $this->getPDO()
                        ->prepare('ROLLBACK')
                        ->execute();
                }

                $result = $this->getPDO()
                    ->prepare('BEGIN IMMEDIATE')
                    ->execute();
            } else {
                $result = $this->getPDO()
                    ->prepare('SAVEPOINT transaction' . $this->inTransaction)
                    ->execute();
            }
        } catch (PDOException $e) {
            throw new TransactionException('Failed to start transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }

        if (!$result) {
            throw new TransactionException('Failed to start transaction');
        }

        $this->inTransaction++;

        return $result;
    }

    /**
     * @inheritDoc
     *
     * Overrides the inherited PDO-driven commit because startTransaction
     * issues a raw `BEGIN IMMEDIATE` (rather than PDO::beginTransaction),
     * so PDO's internal in-transaction flag is never set and PDO::commit()
     * would throw "no active transaction". Mirrors that with a raw COMMIT
     * and SAVEPOINT release for nested levels.
     */
    public function commitTransaction(): bool
    {
        if ($this->inTransaction === 0) {
            return false;
        }

        try {
            if ($this->inTransaction > 1) {
                $result = $this->getPDO()
                    ->prepare('RELEASE SAVEPOINT transaction' . ($this->inTransaction - 1))
                    ->execute();
                $this->inTransaction--;
                return $result;
            }

            $result = $this->getPDO()
                ->prepare('COMMIT')
                ->execute();
            $this->inTransaction = 0;
        } catch (PDOException $e) {
            throw new TransactionException('Failed to commit transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return $result;
    }

    /**
     * @inheritDoc
     *
     * Counterpart to commitTransaction — uses a raw ROLLBACK for the same
     * reason (raw BEGIN IMMEDIATE bypasses PDO's transaction tracking).
     */
    public function rollbackTransaction(): bool
    {
        if ($this->inTransaction === 0) {
            return false;
        }

        try {
            if ($this->inTransaction > 1) {
                $this->getPDO()
                    ->prepare('ROLLBACK TO transaction' . ($this->inTransaction - 1))
                    ->execute();
                $this->inTransaction--;
            } else {
                $this->getPDO()
                    ->prepare('ROLLBACK')
                    ->execute();
                $this->inTransaction = 0;
            }
        } catch (PDOException $e) {
            $this->inTransaction = 0;
            throw new DatabaseException('Failed to rollback transaction: ' . $e->getMessage(), $e->getCode(), $e);
        }

        return true;
    }

    /**
     * Check if Database exists
     * Optionally check if collection exists in Database
     *
     * @param string $database
     * @param string|null $collection
     * @return bool
     * @throws DatabaseException
     */
    public function exists(string $database, ?string $collection = null): bool
    {
        $database = $this->filter($database);

        if (\is_null($collection)) {
            return false;
        }

        $collection = $this->filter($collection);

        $sql = "
			SELECT name FROM sqlite_master 
			WHERE type='table' AND name = :table
		";

        $sql = $this->trigger(Database::EVENT_DATABASE_CREATE, $sql);

        $stmt = $this->getPDO()->prepare($sql);

        $stmt->bindValue(':table', "{$this->getNamespace()}_{$collection}", PDO::PARAM_STR);

        $stmt->execute();

        $document = $stmt->fetchAll();
        $stmt->closeCursor();
        if (!empty($document)) {
            $document = $document[0];
        }

        return (($document['name'] ?? '') === "{$this->getNamespace()}_{$collection}");
    }

    /**
     * Create Database
     *
     * @param string $name
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function create(string $name): bool
    {
        return true;
    }

    /**
     * Delete Database
     *
     * @param string $name
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function delete(string $name): bool
    {
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
     * @throws PDOException
     */
    public function createCollection(string $name, array $attributes = [], array $indexes = []): bool
    {
        $id = $this->filter($name);

        /** @var array<string> $attributeStrings */
        $attributeStrings = [];

        foreach ($attributes as $key => $attribute) {
            $attrId = $this->filter($attribute->getId());

            $attrType = $this->getSQLType(
                $attribute->getAttribute('type'),
                $attribute->getAttribute('size', 0),
                $attribute->getAttribute('signed', true),
                $attribute->getAttribute('array', false),
                $attribute->getAttribute('required', false)
            );

            $attributeStrings[$key] = "`{$attrId}` {$attrType}, ";
        }

        // SQLite stores integers regardless of declared type, but
        // testSchemaAttributes asserts the columnType reads back as
        // `int(11) unsigned` to match MariaDB. Quote the declaration so
        // PRAGMA table_info echoes the exact string under emulation;
        // otherwise use INTEGER, the affinity-correct vanilla form.
        $tenantType = $this->emulateMySQL ? '"INT(11) UNSIGNED"' : 'INTEGER';
        $tenantQuery = $this->sharedTables ? "`_tenant` {$tenantType} DEFAULT NULL," : '';

        $collection = "
			CREATE TABLE {$this->getSQLTable($id)} (
				`_id` INTEGER PRIMARY KEY AUTOINCREMENT,
				`_uid` VARCHAR(36) NOT NULL,
				{$tenantQuery}
				`_createdAt` DATETIME(3) DEFAULT NULL,
				`_updatedAt` DATETIME(3) DEFAULT NULL,
				`_permissions` MEDIUMTEXT DEFAULT NULL".(!empty($attributes) ? ',' : '')."
				" . \substr(\implode(' ', $attributeStrings), 0, -2) . "
			)
		";

        $collection = $this->trigger(Database::EVENT_COLLECTION_CREATE, $collection);

        $permissions = "
			CREATE TABLE {$this->getSQLTable($id . '_perms')} (
				`_id` INTEGER PRIMARY KEY AUTOINCREMENT,
				{$tenantQuery}
				`_type` VARCHAR(12) NOT NULL,
				`_permission` VARCHAR(255) NOT NULL,
				`_document` VARCHAR(255) NOT NULL
			)
		";

        $permissions = $this->trigger(Database::EVENT_COLLECTION_CREATE, $permissions);

        try {
            $this->getPDO()
                ->prepare($collection)
                ->execute();

            $this->getPDO()
                ->prepare($permissions)
                ->execute();

            $this->createIndex($id, '_index1', Database::INDEX_UNIQUE, ['_uid'], [], []);
            $this->createIndex($id, '_created_at', Database::INDEX_KEY, [ '_createdAt'], [], []);
            $this->createIndex($id, '_updated_at', Database::INDEX_KEY, [ '_updatedAt'], [], []);

            $this->createIndex("{$id}_perms", '_index_1', Database::INDEX_UNIQUE, ['_document', '_type', '_permission'], [], []);
            $this->createIndex("{$id}_perms", '_index_2', Database::INDEX_KEY, ['_permission', '_type'], [], []);

            if ($this->sharedTables) {
                $this->createIndex($id, '_tenant_id', Database::INDEX_KEY, [ '_id'], [], []);
            }

            foreach ($indexes as $index) {
                $indexId = $this->filter($index->getId());
                $indexType = $index->getAttribute('type');
                $indexAttributes = $index->getAttribute('attributes', []);
                $indexLengths = $index->getAttribute('lengths', []);
                $indexOrders = $index->getAttribute('orders', []);
                $indexTtl = $index->getAttribute('ttl', 0);

                $this->createIndex($id, $indexId, $indexType, $indexAttributes, $indexLengths, $indexOrders, [], [], $indexTtl);
            }
        } catch (PDOException $e) {
            throw $this->processException($e);
        }
        return true;
    }


    /**
     * Get Collection Size of raw data
     * @param string $collection
     * @return int
     * @throws DatabaseException
     *
     */
    public function getSizeOfCollection(string $collection): int
    {
        $collection = $this->filter($collection);
        $namespace = $this->getNamespace();
        $name = $namespace . '_' . $collection;
        $permissions = $namespace . '_' . $collection . '_perms';
        $ftsPrefix = $this->getFulltextTablePrefix($collection);

        // FTS5 storage lives in `<vtable>_data|_idx|_docsize|_config`
        // shadow tables; sum (pgsize - unused) over all of them.
        $ftsPattern = $this->escapeLikePattern($ftsPrefix) . '%' . $this->escapeLikePattern(self::FTS_TABLE_SUFFIX) . '%';

        $stmt = $this->getPDO()->prepare("
             SELECT COALESCE(SUM(\"pgsize\" - \"unused\"), 0)
             FROM \"dbstat\"
             WHERE name = :name OR name = :perms OR name LIKE :fts_pattern ESCAPE '\\';
        ");

        $stmt->bindParam(':name', $name);
        $stmt->bindParam(':perms', $permissions);
        $stmt->bindParam(':fts_pattern', $ftsPattern);

        try {
            $stmt->execute();
            $size = (int) $stmt->fetchColumn();
            $stmt->closeCursor();
        } catch (PDOException $e) {
            throw new DatabaseException('Failed to get collection size: ' . $e->getMessage());
        }

        return $size;
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
     * Delete Collection
     * @param string $id
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteCollection(string $id): bool
    {
        $id = $this->filter($id);

        // FTS5 shadow tables don't drop with the parent.
        foreach ($this->findFulltextTables($id) as $ftsTable) {
            $sql = "DROP TABLE IF EXISTS `{$ftsTable}`";
            $sql = $this->trigger(Database::EVENT_COLLECTION_DELETE, $sql);
            $this->getPDO()->prepare($sql)->execute();
        }

        $sql = "DROP TABLE IF EXISTS {$this->getSQLTable($id)}";
        $sql = $this->trigger(Database::EVENT_COLLECTION_DELETE, $sql);

        $this->getPDO()
            ->prepare($sql)
            ->execute();

        $sql = "DROP TABLE IF EXISTS {$this->getSQLTable($id . '_perms')}";
        $sql = $this->trigger(Database::EVENT_COLLECTION_DELETE, $sql);

        $this->getPDO()
            ->prepare($sql)
            ->execute();

        unset($this->ftsTableCache[$id]);

        return true;
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
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function updateAttribute(string $collection, string $id, string $type, int $size, bool $signed = true, bool $array = false, ?string $newKey = null, bool $required = false): bool
    {
        if (!empty($newKey) && $newKey !== $id) {
            return $this->renameAttribute($collection, $id, $newKey);
        }

        // SQLite is dynamically typed — `ALTER TABLE ... MODIFY COLUMN` is
        // not supported and a smaller declared size silently accepts
        // larger values. Under MySQL emulation, scan the column and
        // raise the same TruncateException MariaDB throws. Off-
        // emulation the declared size is metadata-only, so skip the
        // scan and let the rename branch (if any) handle the rest.
        if ($this->emulateMySQL && $type === Database::VAR_STRING && $size > 0 && !$array) {
            $name = $this->filter($collection);
            $column = $this->filter($id);

            // Under shared tables the underlying table is shared across
            // tenants; scoping the scan by `_tenant` keeps tenant A's
            // resize from being blocked (and tenant A's metadata from
            // leaking) by an oversized value owned by tenant B.
            $tenantClause = $this->sharedTables ? ' AND `_tenant` = :_tenant' : '';
            $sql = "SELECT 1 FROM {$this->getSQLTable($name)} WHERE LENGTH(`{$column}`) > :max{$tenantClause} LIMIT 1";

            $stmt = $this->getPDO()->prepare($sql);
            $stmt->bindValue(':max', $size, PDO::PARAM_INT);
            if ($this->sharedTables) {
                $stmt->bindValue(':_tenant', $this->tenant, \is_int($this->tenant) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }

            try {
                $stmt->execute();
                $exceeds = $stmt->fetchColumn() !== false;
            } finally {
                $stmt->closeCursor();
            }

            if ($exceeds) {
                throw new TruncateException("Attribute '{$id}' has values exceeding new size {$size}");
            }
        }

        return true;
    }

    /**
     * Delete Attribute
     *
     * @param string $collection
     * @param string $id
     * @param bool $array
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteAttribute(string $collection, string $id, bool $array = false): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);
        $metadataCollection = new Document(['$id' => Database::METADATA]);
        $collection = $this->getDocument($metadataCollection, $name);

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        $indexes = $collection->getAttribute('indexes', []);
        if (\is_string($indexes)) {
            $indexes = \json_decode($indexes, true) ?? [];
        }

        foreach ($indexes as $index) {
            $attributes = $index['attributes'];
            if ($attributes === [$id]) {
                $this->deleteIndex($name, $index['$id']);
            } elseif (\in_array($id, $attributes)) {
                $this->deleteIndex($name, $index['$id']);
                $this->createIndex($name, $index['$id'], $index['type'], \array_diff($attributes, [$id]), $index['lengths'], $index['orders']);
            }
        }

        $sql = "ALTER TABLE {$this->getSQLTable($name)} DROP COLUMN `{$id}`";

        $sql = $this->trigger(Database::EVENT_COLLECTION_DELETE, $sql);

        try {
            return $this->getPDO()
                ->prepare($sql)
                ->execute();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'no such column')) {
                return true;
            }

            throw $e;
        }
    }

    /**
     * Rename Index
     *
     * @param string $collection
     * @param string $old
     * @param string $new
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function renameIndex(string $collection, string $old, string $new): bool
    {
        $metadataCollection = new Document(['$id' => Database::METADATA]);
        $collection = $this->getDocument($metadataCollection, $collection);

        if ($collection->isEmpty()) {
            throw new NotFoundException('Collection not found');
        }

        $old = $this->filter($old);
        $new = $this->filter($new);
        $indexes = $collection->getAttribute('indexes', []);
        if (\is_string($indexes)) {
            $indexes = \json_decode($indexes, true) ?? [];
        }
        $index = null;

        foreach ($indexes as $node) {
            if ($node['key'] === $old) {
                $index = $node;
                break;
            }
        }

        if ($index
            && $this->deleteIndex($collection->getId(), $old)
            && $this->createIndex(
                $collection->getId(),
                $new,
                $index['type'],
                $index['attributes'],
                $index['lengths'],
                $index['orders'],
            )) {
            return true;
        }

        return false;
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
     * @param array<string,string> $indexAttributeTypes
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function createIndex(string $collection, string $id, string $type, array $attributes, array $lengths, array $orders, array $indexAttributeTypes = [], array $collation = [], int $ttl = 1): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        if ($type === Database::INDEX_FULLTEXT) {
            return $this->createFulltextIndex($name, $id, $attributes);
        }

        // Workaround for no support for CREATE INDEX IF NOT EXISTS
        $stmt = $this->getPDO()->prepare("
			SELECT name
			FROM sqlite_master
			WHERE type='index' AND name=:_index;
		");
        $stmt->bindValue(':_index', "{$this->getNamespace()}_{$this->getTenantSegment()}_{$name}_{$id}");
        $stmt->execute();
        $index = $stmt->fetch();
        if (!empty($index)) {
            return true;
        }

        $sql = $this->getSQLIndex($name, $id, $type, $attributes);

        $sql = $this->trigger(Database::EVENT_INDEX_CREATE, $sql);

        try {
            return $this->getPDO()
                ->prepare($sql)
                ->execute();
        } catch (PDOException $e) {
            throw $this->processException($e);
        }
    }

    /**
     * Create an FTS5 virtual table mirroring `$attributes` and the triggers
     * that keep it in sync with the parent collection.
     *
     * @param array<string> $attributes
     * @throws PDOException
     */
    protected function createFulltextIndex(string $collection, string $id, array $attributes): bool
    {
        if (empty($attributes)) {
            throw new DatabaseException('Fulltext index requires at least one attribute');
        }

        $attributes = \array_map(fn (string $a) => $this->getInternalKeyForAttribute($a), $attributes);
        $ftsTable = $this->getFulltextTableName($collection, $attributes);
        $parentTable = "{$this->getNamespace()}_{$collection}";

        $stmt = $this->getPDO()->prepare("
            SELECT name
            FROM sqlite_master
            WHERE type='table' AND name=:_table;
        ");
        $stmt->bindValue(':_table', $ftsTable);
        $stmt->execute();
        $exists = !empty($stmt->fetch());
        $stmt->closeCursor();
        if ($exists) {
            return true;
        }

        $columns = \array_map(fn (string $attr) => $this->filter($attr), $attributes);
        $ftsColumnList = \implode(', ', $columns);
        $columnList = \implode(', ', \array_map(fn (string $c) => "`{$c}`", $columns));
        $newColumnList = \implode(', ', \array_map(fn (string $c) => "NEW.`{$c}`", $columns));
        $oldColumnList = \implode(', ', \array_map(fn (string $c) => "OLD.`{$c}`", $columns));

        // Under shared tables every tenant has a distinct FTS vtable but the
        // parent table is shared, so triggers must filter by `_tenant`
        // literal — otherwise tenant A's vtable accumulates tenant B's
        // tokenized content. The same applies to the initial backfill.
        $tenantLiteral = $this->sharedTables ? $this->getTenantSqlLiteral() : null;
        $insertWhen = $tenantLiteral !== null ? " WHEN NEW.`_tenant` IS {$tenantLiteral}" : '';
        $deleteWhen = $tenantLiteral !== null ? " WHEN OLD.`_tenant` IS {$tenantLiteral}" : '';
        $updateWhen = $tenantLiteral !== null
            ? " WHEN OLD.`_tenant` IS {$tenantLiteral} OR NEW.`_tenant` IS {$tenantLiteral}"
            : '';
        $backfillWhere = $tenantLiteral !== null ? " WHERE `_tenant` IS {$tenantLiteral}" : '';

        $this->startTransaction();
        try {
            $createSql = "CREATE VIRTUAL TABLE `{$ftsTable}` USING fts5({$ftsColumnList}, content=\"{$parentTable}\", content_rowid=\"_id\")";
            $createSql = $this->trigger(Database::EVENT_INDEX_CREATE, $createSql);
            $this->getPDO()->prepare($createSql)->execute();

            $insertSuffix = self::FTS_TRIGGER_INSERT;
            $insertTrigger = "
                CREATE TRIGGER `{$ftsTable}_{$insertSuffix}` AFTER INSERT ON `{$parentTable}`{$insertWhen} BEGIN
                    INSERT INTO `{$ftsTable}` (rowid, {$columnList}) VALUES (NEW.`_id`, {$newColumnList});
                END
            ";
            $this->getPDO()->prepare($insertTrigger)->execute();

            $deleteSuffix = self::FTS_TRIGGER_DELETE;
            $deleteTrigger = "
                CREATE TRIGGER `{$ftsTable}_{$deleteSuffix}` AFTER DELETE ON `{$parentTable}`{$deleteWhen} BEGIN
                    INSERT INTO `{$ftsTable}` (`{$ftsTable}`, rowid, {$columnList}) VALUES ('delete', OLD.`_id`, {$oldColumnList});
                END
            ";
            $this->getPDO()->prepare($deleteTrigger)->execute();

            $updateSuffix = self::FTS_TRIGGER_UPDATE;
            // OF <cols>: skip re-tokenise when only timestamps/permissions change.
            $updateTrigger = "
                CREATE TRIGGER `{$ftsTable}_{$updateSuffix}` AFTER UPDATE OF {$columnList} ON `{$parentTable}`{$updateWhen} BEGIN
                    INSERT INTO `{$ftsTable}` (`{$ftsTable}`, rowid, {$columnList}) VALUES ('delete', OLD.`_id`, {$oldColumnList});
                    INSERT INTO `{$ftsTable}` (rowid, {$columnList}) VALUES (NEW.`_id`, {$newColumnList});
                END
            ";
            $this->getPDO()->prepare($updateTrigger)->execute();

            $backfill = "INSERT INTO `{$ftsTable}` (rowid, {$columnList}) SELECT `_id`, {$columnList} FROM `{$parentTable}`{$backfillWhere}";
            $this->getPDO()->prepare($backfill)->execute();

            $this->commitTransaction();
        } catch (\Throwable $e) {
            // Swallow rollback failures so the original cause keeps its
            // place at the top of the stack — a "Failed to rollback"
            // wrapper hides what actually went wrong.
            try {
                $this->rollbackTransaction();
            } catch (\Throwable) {
            }
            throw $e;
        }

        unset($this->ftsTableCache[$collection]);

        return true;
    }

    /**
     * FTS5 table name keyed off the sorted attribute set. Hashed because
     * `_`-joining `['ab', 'cd_ef']` collides with `['ab_cd', 'ef']`.
     *
     * @param array<string>|string $attributes
     */
    protected function getFulltextTableName(string $collection, array|string $attributes): string
    {
        $attrs = \is_array($attributes) ? $attributes : [$attributes];
        $attrs = \array_map(fn (string $attr) => $this->filter($attr), $attrs);
        \sort($attrs);
        $key = \substr(\hash('sha1', \implode("\0", $attrs)), 0, 16);

        return $this->getFulltextTablePrefix($collection) . $key . self::FTS_TABLE_SUFFIX;
    }

    /**
     * Common LIKE prefix for FTS5 tables on `$collection`. Tenant-scoped
     * under sharedTables so per-tenant drops don't tear down a shared vtable.
     */
    protected function getFulltextTablePrefix(string $collection): string
    {
        if ($this->sharedTables) {
            return "{$this->getNamespace()}_{$this->getTenantSegment()}_{$this->filter($collection)}_";
        }

        return "{$this->getNamespace()}_{$this->filter($collection)}_";
    }

    /**
     * Filtered tenant for identifier interpolation (the base property is
     * `int|string|null`).
     */
    private function getTenantSegment(): string
    {
        return $this->filter((string) ($this->tenant ?? ''));
    }

    /**
     * Tenant rendered as a SQL literal for embedding in trigger bodies and
     * other places where parameter binding isn't available.
     */
    private function getTenantSqlLiteral(): string
    {
        if ($this->tenant === null) {
            return 'NULL';
        }
        if (\is_int($this->tenant)) {
            return (string) $this->tenant;
        }

        return $this->getPDO()->quote((string) $this->tenant);
    }

    /**
     * Delete Index
     *
     * @param string $collection
     * @param string $id
     * @return bool
     * @throws Exception
     * @throws PDOException
     */
    public function deleteIndex(string $collection, string $id): bool
    {
        $name = $this->filter($collection);
        $id = $this->filter($id);

        // If a regular SQLite index with this id exists, take the normal
        // DROP INDEX path. Otherwise the index is either an FTS5 virtual
        // table (whose name is keyed off attributes, not the id) or
        // already absent — try the FTS5 path before erroring.
        $regularIndex = "{$this->getNamespace()}_{$this->getTenantSegment()}_{$name}_{$id}";
        $stmt = $this->getPDO()->prepare("
            SELECT name FROM sqlite_master WHERE type='index' AND name=:_index
        ");
        $stmt->bindValue(':_index', $regularIndex);
        $stmt->execute();
        $hasRegular = $stmt->fetchColumn() !== false;
        // Free the read cursor before issuing DDL — SQLite holds a SHARED
        // lock on the database while a statement has unfetched rows, and
        // any subsequent DROP INDEX / ALTER TABLE under emulated prepares
        // will trip "database table is locked".
        $stmt->closeCursor();

        if (!$hasRegular && $this->dropFulltextIndexById($name, $id)) {
            return true;
        }

        $sql = "DROP INDEX `{$regularIndex}`";
        $sql = $this->trigger(Database::EVENT_INDEX_DELETE, $sql);

        try {
            return $this->getPDO()
                ->prepare($sql)
                ->execute();
        } catch (PDOException $e) {
            if (str_contains($e->getMessage(), 'no such index')) {
                return true;
            }

            throw $e;
        }
    }

    /**
     * Drop the FTS5 vtable backing index `$id` on `$collection`. Returns
     * false when no FTS5 table exists; throws when ambiguous.
     */
    protected function dropFulltextIndexById(string $collection, string $id): bool
    {
        $tables = $this->findFulltextTables($collection);

        if (empty($tables)) {
            return false;
        }

        // Resolve via metadata since the table name is hashed off the
        // attribute set, not the index id.
        $ftsTable = $this->resolveFulltextTableById($collection, $id, $tables);

        if ($ftsTable === null) {
            if (\count($tables) === 1) {
                $ftsTable = $tables[0];
            } else {
                // Returning false would let deleteIndex swallow the
                // resulting "no such index" and report success while
                // the FTS5 tables survived.
                throw new DatabaseException(
                    "Cannot resolve fulltext index '{$id}' on '{$collection}': "
                    . \count($tables) . ' FTS5 tables exist and metadata does not'
                    . ' match any of them.'
                );
            }
        }
        $triggerSuffixes = [
            self::FTS_TRIGGER_INSERT,
            self::FTS_TRIGGER_DELETE,
            self::FTS_TRIGGER_UPDATE,
        ];

        // Atomic teardown: orphaned triggers without their FTS5 table will
        // start failing on every parent write, so do not commit a partial
        // drop if any step fails midway.
        $this->startTransaction();
        try {
            foreach ($triggerSuffixes as $suffix) {
                $this->getPDO()->prepare("DROP TRIGGER IF EXISTS `{$ftsTable}_{$suffix}`")->execute();
            }
            $sql = "DROP TABLE IF EXISTS `{$ftsTable}`";
            $sql = $this->trigger(Database::EVENT_INDEX_DELETE, $sql);
            $this->getPDO()->prepare($sql)->execute();
            $this->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $this->rollbackTransaction();
            } catch (\Throwable) {
            }
            throw $e;
        }

        unset($this->ftsTableCache[$collection]);

        return true;
    }


    /**
     * Resolve the FTS5 table for index `$id` via metadata. Returns null
     * when metadata doesn't reach a candidate.
     *
     * @param array<string> $candidates
     */
    protected function resolveFulltextTableById(string $collection, string $id, array $candidates): ?string
    {
        try {
            $metadataCollection = new Document(['$id' => Database::METADATA]);
            $collectionDoc = $this->getDocument($metadataCollection, $collection);
        } catch (NotFoundException) {
            // Metadata not yet seeded (collection drop during bootstrap).
            // Anything else surfaces — masking PDO errors here would silently
            // fall through to the single-candidate drop path and tear down
            // the wrong table.
            return null;
        }

        if ($collectionDoc->isEmpty()) {
            return null;
        }

        $indexes = $collectionDoc->getAttribute('indexes', []);
        $filteredId = $this->filter($id);

        foreach ($indexes as $index) {
            $indexId = $index instanceof Document
                ? $index->getId()
                : (\is_array($index) ? ($index['$id'] ?? null) : null);

            if ($indexId === null) {
                continue;
            }
            if ($this->filter((string) $indexId) !== $filteredId) {
                continue;
            }

            $type = $index instanceof Document
                ? $index->getAttribute('type')
                : ($index['type'] ?? null);

            if ($type !== Database::INDEX_FULLTEXT) {
                return null;
            }

            $attributes = $index instanceof Document
                ? $index->getAttribute('attributes', [])
                : ($index['attributes'] ?? []);

            $internal = \array_map(
                fn (string $a) => $this->getInternalKeyForAttribute($a),
                (array) $attributes
            );
            $candidate = $this->getFulltextTableName($collection, $internal);

            return \in_array($candidate, $candidates, true) ? $candidate : null;
        }

        return null;
    }

    /**
     * Every FTS5 vtable on `$collection`.
     *
     * @return array<string>
     */
    protected function findFulltextTables(string $collection): array
    {
        // ESCAPE '\\' so the literal `_` separators in the prefix don't
        // act as LIKE wildcards (e.g. `db_users_` matching `db_usersA_`).
        $stmt = $this->getPDO()->prepare("
            SELECT name FROM sqlite_master
            WHERE type='table'
              AND name LIKE :_prefix ESCAPE '\\'
              AND name LIKE :_suffix ESCAPE '\\'
        ");
        $stmt->bindValue(':_prefix', $this->escapeLikePattern($this->getFulltextTablePrefix($collection)) . '%');
        $stmt->bindValue(':_suffix', '%' . $this->escapeLikePattern(self::FTS_TABLE_SUFFIX));
        $stmt->execute();
        $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $stmt->closeCursor();

        return $tables;
    }

    /**
     * Escape `_`, `%`, and `\` so a literal value can be embedded in a
     * LIKE pattern without acting as a wildcard. Pair every call site
     * with an explicit `ESCAPE '\\'` clause.
     */
    private function escapeLikePattern(string $value): string
    {
        return \str_replace(['\\', '_', '%'], ['\\\\', '\_', '\%'], $value);
    }

    /**
     * Create Document
     *
     * @param Document $collection
     * @param Document $document
     * @return Document
     * @throws Exception
     * @throws PDOException
     * @throws DuplicateException
     */
    public function createDocument(Document $collection, Document $document): Document
    {
        $collection = $collection->getId();
        $attributes = $document->getAttributes();
        $attributes['_createdAt'] = $document->getCreatedAt();
        $attributes['_updatedAt'] = $document->getUpdatedAt();
        $attributes['_permissions'] = json_encode($document->getPermissions());

        if ($this->sharedTables) {
            $attributes['_tenant'] = $this->tenant;
        }

        $name = $this->filter($collection);
        $columns = ['_uid'];
        $values = ['_uid'];

        /**
         * Insert Attributes
         */
        $bindIndex = 0;
        foreach ($attributes as $attribute => $value) { // Parse statement
            $column = $this->filter($attribute);
            $values[] = 'value_' . $bindIndex;
            $columns[] = "`{$column}`";
            $bindIndex++;
        }

        // Insert manual id if set
        if (!empty($document->getSequence())) {
            $values[] = '_id';
            $columns[] = "_id";
        }

        $sql = "
			INSERT INTO `{$this->getNamespace()}_{$name}` (".\implode(', ', $columns).") 
			VALUES (:".\implode(', :', $values).");
		";

        $sql = $this->trigger(Database::EVENT_DOCUMENT_CREATE, $sql);

        $stmt = $this->getPDO()->prepare($sql);

        $stmt->bindValue(':_uid', $document->getId(), PDO::PARAM_STR);

        // Bind internal id if set
        if (!empty($document->getSequence())) {
            $stmt->bindValue(':_id', $document->getSequence(), PDO::PARAM_STR);
        }

        $attributeIndex = 0;
        foreach ($attributes as $attribute => $value) {
            if (is_array($value)) { // arrays & objects should be saved as strings
                $value = json_encode($value);
            }

            $bindKey = 'value_' . $attributeIndex;
            $attribute = $this->filter($attribute);
            $value = (is_bool($value)) ? (int)$value : $value;
            $stmt->bindValue(':' . $bindKey, $value, $this->getPDOType($value));
            $attributeIndex++;
        }

        $permissions = [];
        foreach (Database::PERMISSIONS as $type) {
            foreach ($document->getPermissionsByType($type) as $permission) {
                $permission = \str_replace('"', '', $permission);
                $tenantQuery = $this->sharedTables ? ', :_tenant' : '';
                $permissions[] = "('{$type}', '{$permission}', '{$document->getId()}' {$tenantQuery})";
            }
        }

        if (!empty($permissions)) {
            $tenantQuery = $this->sharedTables ? ', _tenant' : '';

            $queryPermissions = "
				INSERT INTO `{$this->getNamespace()}_{$name}_perms` (_type, _permission, _document {$tenantQuery})
				VALUES " . \implode(', ', $permissions);

            $queryPermissions = $this->trigger(Database::EVENT_PERMISSIONS_CREATE, $queryPermissions);

            $stmtPermissions = $this->getPDO()->prepare($queryPermissions);

            if ($this->sharedTables) {
                $stmtPermissions->bindValue(':_tenant', $this->tenant);
            }
        }

        try {
            $stmt->execute();

            $document['$sequence'] = (int) $this->getPDO()->lastInsertId();

            if (isset($stmtPermissions)) {
                $stmtPermissions->execute();
            }
        } catch (PDOException $e) {
            throw $this->processException($e);
        }


        return $document;
    }

    /**
     * Update Document
     *
     * @param Document $collection
     * @param string $id
     * @param Document $document
     * @param bool $skipPermissions
     * @return Document
     * @throws Exception
     * @throws PDOException
     * @throws DuplicateException
     */
    public function updateDocument(Document $collection, string $id, Document $document, bool $skipPermissions): Document
    {
        $spatialAttributes = $this->getSpatialAttributes($collection);
        $collection = $collection->getId();
        $attributes = $document->getAttributes();
        $attributes['_createdAt'] = $document->getCreatedAt();
        $attributes['_updatedAt'] = $document->getUpdatedAt();
        $attributes['_permissions'] = json_encode($document->getPermissions());
        $attributes['_uid'] = $document->getId();

        if ($this->sharedTables) {
            $attributes['_tenant'] = $document->getTenant();
        }

        $name = $this->filter($collection);
        $columns = '';

        if (!$skipPermissions) {
            $newUid = $document->offsetExists('$id') ? $document->getId() : $id;

            $sql = "
			DELETE FROM `{$this->getNamespace()}_{$name}_perms`
			WHERE _document = :_uid
			{$this->getTenantQuery($collection)}
		";

            $sql = $this->trigger(Database::EVENT_PERMISSIONS_DELETE, $sql);

            $stmtRemovePermissions = $this->getPDO()->prepare($sql);
            $stmtRemovePermissions->bindValue(':_uid', $id);
            if ($this->sharedTables) {
                $stmtRemovePermissions->bindValue(':_tenant', $document->getTenant());
            }

            $values = [];
            $binds = [];
            foreach (Database::PERMISSIONS as $type) {
                foreach ($document->getPermissionsByType($type) as $i => $permission) {
                    $tenantQuery = $this->sharedTables ? ', :_tenant' : '';
                    $values[] = "(:_uid, '{$type}', :_add_{$type}_{$i} {$tenantQuery})";
                    $binds[":_add_{$type}_{$i}"] = $permission;
                }
            }

            if (!empty($values)) {
                $tenantQuery = $this->sharedTables ? ', _tenant' : '';

                $sql = "
			   INSERT INTO `{$this->getNamespace()}_{$name}_perms` (_document, _type, _permission {$tenantQuery})
			   VALUES " . \implode(', ', $values);

                $sql = $this->trigger(Database::EVENT_PERMISSIONS_CREATE, $sql);

                $stmtAddPermissions = $this->getPDO()->prepare($sql);
                $stmtAddPermissions->bindValue(":_uid", $newUid);
                if ($this->sharedTables) {
                    $stmtAddPermissions->bindValue(":_tenant", $document->getTenant());
                }

                foreach ($binds as $key => $permission) {
                    $stmtAddPermissions->bindValue($key, $permission);
                }
            }
        }

        /**
         * Update Attributes
         */
        $keyIndex = 0;
        $operatorBinds = [];

        foreach ($attributes as $attribute => $value) {
            $column = $this->filter($attribute);

            // Check if this is an operator, spatial attribute, or regular attribute
            if (Operator::isOperator($value)) {
                $operatorSQL = $this->getOperatorSQL($column, $value, $operatorBinds);
                $columns .= $operatorSQL;
            } elseif ($this->getSupportForSpatialAttributes() && \in_array($attribute, $spatialAttributes, true)) {
                $bindKey = 'key_' . $keyIndex;
                $columns .= "`{$column}` = " . $this->getSpatialGeomFromText(':' . $bindKey);
                $keyIndex++;
            } else {
                $bindKey = 'key_' . $keyIndex;
                $columns .= "`{$column}`" . '=:' . $bindKey;
                $keyIndex++;
            }

            $columns .= ',';
        }

        // Remove trailing comma
        $columns = rtrim($columns, ',');

        $sql = "
			UPDATE `{$this->getNamespace()}_{$name}`
			SET {$columns}
			WHERE _uid = :_existingUid
			{$this->getTenantQuery($collection)}
		";

        $sql = $this->trigger(Database::EVENT_DOCUMENT_UPDATE, $sql);

        $stmt = $this->getPDO()->prepare($sql);

        $stmt->bindValue(':_existingUid', $id);

        if ($this->sharedTables) {
            $stmt->bindValue(':_tenant', $this->tenant);
        }

        // Bind values for non-operator attributes and operator parameters
        $keyIndex = 0;
        foreach ($attributes as $attribute => $value) {
            // Handle operators separately
            if (Operator::isOperator($value)) {
                continue;
            }

            // Convert spatial arrays to WKT, json_encode non-spatial arrays
            if (\in_array($attribute, $spatialAttributes, true)) {
                if (\is_array($value)) {
                    $value = $this->convertArrayToWKT($value);
                }
            } elseif (is_array($value)) { // arrays & objects should be saved as strings
                $value = json_encode($value);
            }

            $bindKey = 'key_' . $keyIndex;
            $value = (is_bool($value)) ? (int)$value : $value;
            $stmt->bindValue(':' . $bindKey, $value, $this->getPDOType($value));
            $keyIndex++;
        }

        foreach ($operatorBinds as $bindKey => $bindValue) {
            $stmt->bindValue($bindKey, $bindValue, $this->getPDOType($bindValue));
        }

        try {
            $stmt->execute();
            if (isset($stmtRemovePermissions)) {
                $stmtRemovePermissions->execute();
            }
            if (isset($stmtAddPermissions)) {
                $stmtAddPermissions->execute();
            }
        } catch (PDOException $e) {
            throw $this->processException($e);
        }

        return $document;
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

    public function getSupportForQueryContains(): bool
    {
        return false;
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
        // FTS5's unicode61 tokenizer strips characters like `@` and `.`
        // before indexing, so a search for "al@ba.io" applied as a prefix
        // wildcard ("al ba io*") matches a doc containing "al@ba.io" the
        // same way the non-wildcard branch does. The upstream test gates
        // its expectations on this flag and the false branch matches
        // SQLite's actual tokenisation behaviour; flagging as true would
        // claim a behavioural distinction we don't deliver.
        return false;
    }

    /**
     * Are timeouts supported?
     *
     * @return bool
     */
    public function getSupportForTimeouts(): bool
    {
        // The adapter does no per-query timeout enforcement and therefore
        // can't translate a tripped budget into Utopia\Database\Exception\Timeout
        // the way MariaDB/Postgres do. Stay false rather than mislead callers
        // that rely on Database::setTimeout() actually firing.
        return false;
    }

    public function getSupportForRelationships(): bool
    {
        return true;
    }

    public function getSupportForUpdateLock(): bool
    {
        // SQLite has no row-level locking. The parser accepts FOR UPDATE
        // as syntactic sugar but the planner still escalates to a
        // RESERVED/EXCLUSIVE lock on the database, which deadlocks
        // subsequent DDL like DROP INDEX inside the same transaction.
        // Stay false so the SELECT path doesn't append the clause.
        return false;
    }

    /**
     * Is attribute resizing supported?
     *
     * @return bool
     */
    public function getSupportForAttributeResizing(): bool
    {
        // SQLite is dynamically typed with no MODIFY COLUMN. When
        // emulating MySQL, updateAttribute scans the column on
        // resize-down and raises TruncateException to match MariaDB's
        // contract. Off-emulation, declared sizes are metadata-only.
        return $this->emulateMySQL;
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
     * Is get schema attributes supported?
     *
     * @return bool
     */
    public function getSupportForSchemaAttributes(): bool
    {
        return true;
    }

    public function getSupportForSchemaIndexes(): bool
    {
        return true;
    }

    /**
     * Is upsert supported?
     *
     * @return bool
     */
    public function getSupportForUpserts(): bool
    {
        // ON CONFLICT DO UPDATE is native SQLite, not MariaDB emulation.
        return true;
    }

    /**
     * SQLite has no JSON_OVERLAPS — fall back to the LIKE-based default
     * inherited from MariaDB::getSQLCondition for CONTAINS queries on arrays.
     */
    public function getSupportForJSONOverlaps(): bool
    {
        return false;
    }

    public function getSupportForUpsertOnUniqueIndex(): bool
    {
        return false;
    }

    /**
     * Is hostname supported?
     *
     * @return bool
     */
    public function getSupportForHostname(): bool
    {
        return false;
    }

    /**
     * Is batch create attributes supported?
     *
     * @return bool
     */
    public function getSupportForBatchCreateAttributes(): bool
    {
        return true;
    }

    public function getSupportForSpatialAttributes(): bool
    {
        return false; // SQLite doesn't have native spatial support
    }

    public function getSupportForObject(): bool
    {
        return false;
    }

    public function getSupportForUnsignedBigInt(): bool
    {
        return false;
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

    public function getSupportForSpatialIndexNull(): bool
    {
        return false; // SQLite doesn't have native spatial support
    }

    /**
     * Override getSpatialGeomFromText to return placeholder unchanged for SQLite
     * SQLite does not support ST_GeomFromText, so we return the raw placeholder
     *
     * @param string $wktPlaceholder
     * @param int|null $srid
     * @return string
     */
    protected function getSpatialGeomFromText(string $wktPlaceholder, ?int $srid = null): string
    {
        return $wktPlaceholder;
    }

    /**
     * Get SQL Index Type
     *
     * @param string $type
     * @return string
     * @throws Exception
     */
    protected function getSQLIndexType(string $type): string
    {
        switch ($type) {
            case Database::INDEX_KEY:
                return 'INDEX';

            case Database::INDEX_UNIQUE:
                return 'UNIQUE INDEX';

            case Database::INDEX_FULLTEXT:
                // Fulltext is handled via FTS5 virtual tables in
                // createFulltextIndex; reaching this codepath means a
                // caller bypassed that route and would emit invalid SQL.
                throw new DatabaseException('Fulltext indexes use createFulltextIndex(), not getSQLIndexType');

            default:
                throw new DatabaseException('Unknown index type: ' . $type . '. Must be one of ' . Database::INDEX_KEY . ', ' . Database::INDEX_UNIQUE . ', ' . Database::INDEX_FULLTEXT);
        }
    }

    /**
     * Get SQL Index
     *
     * @param string $collection
     * @param string $id
     * @param string $type
     * @param array<string> $attributes
     * @return string
     * @throws Exception
     */
    protected function getSQLIndex(string $collection, string $id, string $type, array $attributes): string
    {
        $postfix = '';

        switch ($type) {
            case Database::INDEX_KEY:
                $type = 'INDEX';
                break;

            case Database::INDEX_UNIQUE:
                $type = 'UNIQUE INDEX';
                $postfix = 'COLLATE NOCASE';

                break;

            default:
                throw new DatabaseException('Unknown index type: ' . $type . '. Must be one of ' . Database::INDEX_KEY . ', ' . Database::INDEX_UNIQUE . ', ' . Database::INDEX_FULLTEXT);
        }

        $attributes = \array_map(fn ($attribute) => $this->getInternalKeyForAttribute($attribute), $attributes);

        foreach ($attributes as $key => $attribute) {
            $attribute = $this->filter($attribute);

            $attributes[$key] = "`{$attribute}` {$postfix}";
        }

        $key = "`{$this->getNamespace()}_{$this->getTenantSegment()}_{$collection}_{$id}`";
        $attributes = implode(', ', $attributes);

        if ($this->sharedTables) {
            $attributes = "`_tenant` {$postfix}, {$attributes}";
        }

        return "CREATE {$type} {$key} ON `{$this->getNamespace()}_{$collection}` ({$attributes})";
    }

    /**
     * Get SQL table
     *
     * @param string $name
     * @return string
     */
    protected function getSQLTable(string $name): string
    {
        return $this->quote("{$this->getNamespace()}_{$this->filter($name)}");
    }

    /**
     * Get list of keywords that cannot be used
     *  Refference: https://www.sqlite.org/lang_keywords.html
     *
     * @return array<string>
     */
    public function getKeywords(): array
    {
        return [
            'ABORT',
            'ACTION',
            'ADD',
            'AFTER',
            'ALL',
            'ALTER',
            'ALWAYS',
            'ANALYZE',
            'AND',
            'AS',
            'ASC',
            'ATTACH',
            'AUTOINCREMENT',
            'BEFORE',
            'BEGIN',
            'BETWEEN',
            'BY',
            'CASCADE',
            'CASE',
            'CAST',
            'CHECK',
            'COLLATE',
            'COLUMN',
            'COMMIT',
            'CONFLICT',
            'CONSTRAINT',
            'CREATE',
            'CROSS',
            'CURRENT',
            'CURRENT_DATE',
            'CURRENT_TIME',
            'CURRENT_TIMESTAMP',
            'DATABASE',
            'DEFAULT',
            'DEFERRABLE',
            'DEFERRED',
            'DELETE',
            'DESC',
            'DETACH',
            'DISTINCT',
            'DO',
            'DROP',
            'EACH',
            'ELSE',
            'END',
            'ESCAPE',
            'EXCEPT',
            'EXCLUDE',
            'EXCLUSIVE',
            'EXISTS',
            'EXPLAIN',
            'FAIL',
            'FILTER',
            'FIRST',
            'FOLLOWING',
            'FOR',
            'FOREIGN',
            'FROM',
            'FULL',
            'GENERATED',
            'GLOB',
            'GROUP',
            'GROUPS',
            'HAVING',
            'IF',
            'IGNORE',
            'IMMEDIATE',
            'IN',
            'INDEX',
            'INDEXED',
            'INITIALLY',
            'INNER',
            'INSERT',
            'INSTEAD',
            'INTERSECT',
            'INTO',
            'IS',
            'ISNULL',
            'JOIN',
            'KEY',
            'LAST',
            'LEFT',
            'LIKE',
            'LIMIT',
            'MATCH',
            'MATERIALIZED',
            'NATURAL',
            'NO',
            'NOT',
            'NOTHING',
            'NOTNULL',
            'NULL',
            'NULLS',
            'OF',
            'OFFSET',
            'ON',
            'OR',
            'ORDER',
            'OTHERS',
            'OUTER',
            'OVER',
            'PARTITION',
            'PLAN',
            'PRAGMA',
            'PRECEDING',
            'PRIMARY',
            'QUERY',
            'RAISE',
            'RANGE',
            'RECURSIVE',
            'REFERENCES',
            'REGEXP',
            'REINDEX',
            'RELEASE',
            'RENAME',
            'REPLACE',
            'RESTRICT',
            'RETURNING',
            'RIGHT',
            'ROLLBACK',
            'ROW',
            'ROWS',
            'SAVEPOINT',
            'SELECT',
            'SET',
            'TABLE',
            'TEMP',
            'TEMPORARY',
            'THEN',
            'TIES',
            'TO',
            'TRANSACTION',
            'TRIGGER',
            'UNBOUNDED',
            'UNION',
            'UNIQUE',
            'UPDATE',
            'USING',
            'VACUUM',
            'VALUES',
            'VIEW',
            'VIRTUAL',
            'WHEN',
            'WHERE',
            'WINDOW',
            'WITH',
            'WITHOUT',
        ];
    }

    protected function processException(PDOException $e): \Exception
    {
        // Timeout
        if ($e->getCode() === 'HY000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 3024) {
            return new TimeoutException('Query timed out', $e->getCode(), $e);
        }

        // Table/index already exists (SQLITE_ERROR with "already exists" message)
        if ($e->getCode() === 'HY000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1 && stripos($e->getMessage(), 'already exists') !== false) {
            return new DuplicateException('Collection already exists', $e->getCode(), $e);
        }

        // Table not found (SQLITE_ERROR with "no such table" message)
        if ($e->getCode() === 'HY000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 1 && stripos($e->getMessage(), 'no such table') !== false) {
            return new NotFoundException('Collection not found', $e->getCode(), $e);
        }

        // Duplicate - SQLite uses various error codes for constraint violations:
        // - Error code 19 is SQLITE_CONSTRAINT (includes UNIQUE violations)
        // - Error code 1 is also used for some duplicate cases
        // - SQL state '23000' is integrity constraint violation
        if (
            ($e->getCode() === 'HY000' && isset($e->errorInfo[1]) && ($e->errorInfo[1] === 1 || $e->errorInfo[1] === 19)) ||
            $e->getCode() === '23000'
        ) {
            $message = $e->getMessage();
            if (
                (isset($e->errorInfo[1]) && $e->errorInfo[1] === 19) ||
                $e->getCode() === '23000' ||
                stripos($message, 'unique') !== false ||
                stripos($message, 'duplicate') !== false
            ) {
                $columns = $this->getViolatedColumns($message);
                if ($columns !== null && $columns !== ['_uid'] && $columns !== ['_tenant', '_uid']) {
                    return new UniqueException('Unique index violation', $e->getCode(), $e);
                }
                return new DuplicateException('Document already exists', $e->getCode(), $e);
            }
        }

        // String or BLOB exceeds size limit
        if ($e->getCode() === 'HY000' && isset($e->errorInfo[1]) && $e->errorInfo[1] === 18) {
            return new LimitException('Value too large', $e->getCode(), $e);
        }

        return $e;
    }

    /**
     * Extract the violated columns from a constraint error, e.g.
     * "UNIQUE constraint failed: movies._tenant, movies._uid" resolves to
     * ['_tenant', '_uid']. Returns null when the message cannot be parsed.
     *
     * @return array<string>|null
     */
    protected function getViolatedColumns(string $message): ?array
    {
        if (\preg_match('/UNIQUE constraint failed:\s*(.+)/', $message, $matches) !== 1) {
            return null;
        }

        $columns = \array_map(function (string $column): string {
            $separator = \strrpos($column, '.');
            if ($separator !== false) {
                $column = \substr($column, $separator + 1);
            }

            return \trim($column, " \t`\"");
        }, \explode(',', $matches[1]));

        \sort($columns);

        return $columns;
    }

    public function getSupportForSpatialIndexOrder(): bool
    {
        return false;
    }
    public function getSupportForBoundaryInclusiveContains(): bool
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
     * Adapter supports optional spatial attributes with existing rows.
     *
     * @return bool
     */
    public function getSupportForOptionalSpatialAttributeWithExistingRows(): bool
    {
        return true;
    }

    /**
     * Get the SQL function for random ordering
     *
     * @return string
     */
    protected function getRandomOrder(): string
    {
        return 'RANDOM()';
    }

    /**
     * Check if SQLite math functions (like POWER) are available
     * SQLite must be compiled with -DSQLITE_ENABLE_MATH_FUNCTIONS
     *
     * @return bool
     */
    private function getSupportForMathFunctions(): bool
    {
        static $available = null;

        if ($available !== null) {
            return $available;
        }

        try {
            // Test if POWER function exists by attempting to use it
            $stmt = $this->getPDO()->query('SELECT POWER(2, 3) as test');
            $result = $stmt->fetch();
            $available = ($result['test'] == 8);
            return $available;
        } catch (PDOException $e) {
            // Function doesn't exist
            $available = false;
            return false;
        }
    }

    /**
     * Get SQL expression for operator
     *
     * IMPORTANT: SQLite JSON Limitations
     * -----------------------------------
     * Array operators using json_each() and json_group_array() have type conversion behavior:
     * - Numbers are preserved but may lose precision (e.g., 1.0 becomes 1)
     * - Booleans become integers (true→1, false→0)
     * - Strings remain strings
     * - Objects and nested arrays are converted to JSON strings
     *
     * This is inherent to SQLite's JSON implementation and affects: ARRAY_APPEND, ARRAY_PREPEND,
     * ARRAY_UNIQUE, ARRAY_INTERSECT, ARRAY_DIFF, ARRAY_INSERT, and ARRAY_REMOVE.
     *
     * @param string $column
     * @param Operator $operator
     * @param array<string, mixed> $binds
     * @return ?string
     */
    protected function getOperatorSQL(string $column, Operator $operator, array &$binds): ?string
    {
        $quotedColumn = $this->quote($column);
        $method = $operator->getMethod();

        switch ($method) {
            // Numeric operators
            case Operator::TYPE_INCREMENT:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);

                if (isset($values[1])) {
                    $maxKey = $this->registerOperatorBind($binds, $values[1]);
                    return "{$quotedColumn} = CASE
                        WHEN COALESCE({$quotedColumn}, 0) + :$bindKey > :$maxKey THEN COALESCE({$quotedColumn}, 0)
                        ELSE COALESCE({$quotedColumn}, 0) + :$bindKey
                    END";
                }
                return "{$quotedColumn} = COALESCE({$quotedColumn}, 0) + :$bindKey";

            case Operator::TYPE_DECREMENT:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);

                if (isset($values[1])) {
                    $minKey = $this->registerOperatorBind($binds, $values[1]);
                    return "{$quotedColumn} = CASE
                        WHEN COALESCE({$quotedColumn}, 0) - :$bindKey < :$minKey THEN COALESCE({$quotedColumn}, 0)
                        ELSE COALESCE({$quotedColumn}, 0) - :$bindKey
                    END";
                }
                return "{$quotedColumn} = COALESCE({$quotedColumn}, 0) - :$bindKey";

            case Operator::TYPE_MULTIPLY:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);

                if (isset($values[1])) {
                    $maxKey = $this->registerOperatorBind($binds, $values[1]);
                    return "{$quotedColumn} = CASE
                        WHEN COALESCE({$quotedColumn}, 0) * :$bindKey > :$maxKey THEN COALESCE({$quotedColumn}, 0)
                        ELSE COALESCE({$quotedColumn}, 0) * :$bindKey
                    END";
                }
                return "{$quotedColumn} = COALESCE({$quotedColumn}, 0) * :$bindKey";

            case Operator::TYPE_DIVIDE:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);

                if (isset($values[1])) {
                    $minKey = $this->registerOperatorBind($binds, $values[1]);
                    return "{$quotedColumn} = CASE
                        WHEN :$bindKey != 0 AND COALESCE({$quotedColumn}, 0) / :$bindKey < :$minKey THEN COALESCE({$quotedColumn}, 0)
                        ELSE COALESCE({$quotedColumn}, 0) / :$bindKey
                    END";
                }
                return "{$quotedColumn} = COALESCE({$quotedColumn}, 0) / :$bindKey";

            case Operator::TYPE_MODULO:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 1);
                return "{$quotedColumn} = COALESCE({$quotedColumn}, 0) % :$bindKey";

            case Operator::TYPE_POWER:
                if (!$this->getSupportForMathFunctions()) {
                    throw new DatabaseException(
                        'SQLite POWER operator requires math functions. ' .
                        'Compile SQLite with -DSQLITE_ENABLE_MATH_FUNCTIONS or use multiply operators instead.'
                    );
                }

                $values = $operator->getValues();
                $exponent = $values[0] ?? 1;
                $bindKey = $this->registerOperatorBind($binds, $exponent);

                if (isset($values[1])) {
                    $maxKey = $this->registerOperatorBind($binds, $values[1]);
                    $col = "COALESCE({$quotedColumn}, 0)";

                    // Leave the value unchanged only for undefined inputs, then apply the power if
                    // the result stays within the max. The exponent is constant, so only the
                    // undefined guard its value can actually trigger is emitted.
                    $oddInteger = \floor($exponent) == $exponent && ((int) $exponent) % 2 !== 0;

                    $whens = [];
                    if ($exponent < 0) {
                        // 0 to a negative power is undefined.
                        $whens[] = "WHEN {$col} = 0 THEN {$col}";
                    }
                    if (\floor($exponent) != $exponent) {
                        // A negative base to a fractional exponent is not a real number.
                        $whens[] = "WHEN {$col} < 0 THEN {$col}";
                    }
                    // Cap by magnitude via logarithms so POWER() never runs on a value that would
                    // overflow (base^exp > max  <=>  exp * LN(base) > LN(max)).
                    if ($exponent == 0) {
                        // Every base to the zeroth power is 1 (including 0^0), which the magnitude
                        // check below can't see for a base of 0. The result 1 exceeds the max when
                        // max < 1, i.e. LN(max) < 0 (LN also coerces the bound value numerically).
                        $whens[] = "WHEN LN(:$maxKey) < 0 THEN {$col}";
                    } elseif ($oddInteger) {
                        // An odd exponent keeps a negative base negative, and a negative result is
                        // always within a positive max, so only cap positive bases; negative bases
                        // fall through to POWER() and their (negative) result is applied.
                        $whens[] = "WHEN {$col} > 0 AND :$bindKey * LN({$col}) > LN(:$maxKey) THEN {$col}";
                    } else {
                        // Otherwise the result is non-negative, so its magnitude equals its value —
                        // cap either sign. ABS() keeps LN() defined for a negative even-power base.
                        $whens[] = "WHEN {$col} <> 0 AND :$bindKey * LN(ABS({$col})) > LN(:$maxKey) THEN {$col}";
                    }

                    $whenSql = \implode(' ', $whens);
                    return "{$quotedColumn} = CASE {$whenSql} ELSE POWER({$col}, :$bindKey) END";
                }
                return "{$quotedColumn} = POWER(COALESCE({$quotedColumn}, 0), :$bindKey)";

                // String operators
            case Operator::TYPE_STRING_CONCAT:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? '');
                return "{$quotedColumn} = IFNULL({$quotedColumn}, '') || :$bindKey";

            case Operator::TYPE_STRING_REPLACE:
                $values = $operator->getValues();
                $searchKey = $this->registerOperatorBind($binds, $values[0] ?? '');
                $replaceKey = $this->registerOperatorBind($binds, $values[1] ?? '');
                return "{$quotedColumn} = REPLACE({$quotedColumn}, :$searchKey, :$replaceKey)";

                // Boolean operators
            case Operator::TYPE_TOGGLE:
                // SQLite: toggle boolean (0 or 1), treat NULL as 0
                return "{$quotedColumn} = CASE WHEN COALESCE({$quotedColumn}, 0) = 0 THEN 1 ELSE 0 END";

                // Array operators
            case Operator::TYPE_ARRAY_APPEND:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                // SQLite: merge arrays by using json_group_array on extracted elements
                // We use json_each to extract elements from both arrays and combine them
                return "{$quotedColumn} = (
                    SELECT json_group_array(value)
                    FROM (
                        SELECT value FROM json_each(IFNULL({$quotedColumn}, '[]'))
                        UNION ALL
                        SELECT value FROM json_each(:$bindKey)
                    )
                )";

            case Operator::TYPE_ARRAY_PREPEND:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                // SQLite: prepend by extracting and recombining with new elements first
                return "{$quotedColumn} = (
                    SELECT json_group_array(value)
                    FROM (
                        SELECT value FROM json_each(:$bindKey)
                        UNION ALL
                        SELECT value FROM json_each(IFNULL({$quotedColumn}, '[]'))
                    )
                )";

            case Operator::TYPE_ARRAY_UNIQUE:
                // SQLite: get distinct values from JSON array
                return "{$quotedColumn} = (
                    SELECT json_group_array(DISTINCT value)
                    FROM json_each(IFNULL({$quotedColumn}, '[]'))
                )";

            case Operator::TYPE_ARRAY_REMOVE:
                $values = $operator->getValues();
                $removeValue = $values[0] ?? null;
                // Cast scalars to string so the value binds as PDO::PARAM_STR, preserving the
                // pre-refactor behavior (it was bound with an explicit PARAM_STR). Without the
                // cast, getPDOType() would bind a number as PARAM_INT. Do not drop it.
                $removeValue = is_array($removeValue) ? json_encode($removeValue) : (string)$removeValue;
                $bindKey = $this->registerOperatorBind($binds, $removeValue);
                // SQLite: remove specific value from array
                return "{$quotedColumn} = (
                    SELECT json_group_array(value)
                    FROM json_each(IFNULL({$quotedColumn}, '[]'))
                    WHERE value != :$bindKey
                )";

            case Operator::TYPE_ARRAY_INSERT:
                $values = $operator->getValues();
                $indexKey = $this->registerOperatorBind($binds, $values[0] ?? 0);
                $valueKey = $this->registerOperatorBind($binds, json_encode($values[1] ?? null));
                // SQLite: Insert element at specific index by:
                // 1. Take elements before index (0 to index-1)
                // 2. Add new element
                // 3. Take elements from index to end
                // The bound value is JSON-encoded by parent, json() parses it back to a value,
                // then we wrap it in json_array() and extract to get the same format as json_each()
                return "{$quotedColumn} = (
                    SELECT json_group_array(value)
                    FROM (
                        SELECT value, rownum
                        FROM (
                            SELECT value, (ROW_NUMBER() OVER ()) - 1 as rownum
                            FROM json_each(IFNULL({$quotedColumn}, '[]'))
                        )
                        WHERE rownum < :$indexKey
                        UNION ALL
                        SELECT value, :$indexKey as rownum
                        FROM json_each(json_array(json(:$valueKey)))
                        UNION ALL
                        SELECT value, rownum + 1 as rownum
                        FROM (
                            SELECT value, (ROW_NUMBER() OVER ()) - 1 as rownum
                            FROM json_each(IFNULL({$quotedColumn}, '[]'))
                        )
                        WHERE rownum >= :$indexKey
                        ORDER BY rownum
                    )
                )";

            case Operator::TYPE_ARRAY_INTERSECT:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                // SQLite: keep only values that exist in both arrays
                return "{$quotedColumn} = (
                    SELECT json_group_array(value)
                    FROM json_each(IFNULL({$quotedColumn}, '[]'))
                    WHERE value IN (SELECT value FROM json_each(:$bindKey))
                )";

            case Operator::TYPE_ARRAY_DIFF:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, json_encode($values));
                // SQLite: remove values that exist in the comparison array
                return "{$quotedColumn} = (
                    SELECT json_group_array(value)
                    FROM json_each(IFNULL({$quotedColumn}, '[]'))
                    WHERE value NOT IN (SELECT value FROM json_each(:$bindKey))
                )";

            case Operator::TYPE_ARRAY_FILTER:
                $values = $operator->getValues();
                if (empty($values)) {
                    // No filter criteria, return array unchanged
                    return "{$quotedColumn} = {$quotedColumn}";
                }

                $filterType = $values[0]; // 'equal', 'notEqual', 'isNull', 'isNotNull', 'greaterThan', etc.

                switch ($filterType) {
                    case 'isNull':
                        // Filter for null values - no bind parameter needed
                        return "{$quotedColumn} = (
                            SELECT json_group_array(value)
                            FROM json_each(IFNULL({$quotedColumn}, '[]'))
                            WHERE value IS NULL
                        )";

                    case 'isNotNull':
                        // Filter out null values - no bind parameter needed
                        return "{$quotedColumn} = (
                            SELECT json_group_array(value)
                            FROM json_each(IFNULL({$quotedColumn}, '[]'))
                            WHERE value IS NOT NULL
                        )";

                    case 'equal':
                    case 'notEqual':
                    case 'greaterThan':
                    case 'greaterThanEqual':
                    case 'lessThan':
                    case 'lessThanEqual':
                        if (\count($values) < 2) {
                            return "{$quotedColumn} = {$quotedColumn}";
                        }

                        $filterValue = $values[1];
                        $filterValue = (is_bool($filterValue)) ? (int)$filterValue : $filterValue;
                        $bindKey = $this->registerOperatorBind($binds, $filterValue);

                        $operator = match ($filterType) {
                            'equal' => '=',
                            'notEqual' => '!=',
                            'greaterThan' => '>',
                            'greaterThanEqual' => '>=',
                            'lessThan' => '<',
                            'lessThanEqual' => '<=',
                            default => throw new OperatorException('Unsupported filter type: ' . $filterType),
                        };

                        // For numeric comparisons, cast to REAL; for equal/notEqual, use text comparison
                        $isNumericComparison = \in_array($filterType, ['greaterThan', 'greaterThanEqual', 'lessThan', 'lessThanEqual']);
                        if ($isNumericComparison) {
                            return "{$quotedColumn} = (
                                SELECT json_group_array(value)
                                FROM json_each(IFNULL({$quotedColumn}, '[]'))
                                WHERE CAST(value AS REAL) $operator CAST(:$bindKey AS REAL)
                            )";
                        } else {
                            return "{$quotedColumn} = (
                                SELECT json_group_array(value)
                                FROM json_each(IFNULL({$quotedColumn}, '[]'))
                                WHERE value $operator :$bindKey
                            )";
                        }

                        // no break
                    default:
                        return "{$quotedColumn} = {$quotedColumn}";
                }

                // Date operators
                // no break
            case Operator::TYPE_DATE_ADD_DAYS:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 0);

                return "{$quotedColumn} = datetime({$quotedColumn}, :$bindKey || ' days')";

            case Operator::TYPE_DATE_SUB_DAYS:
                $values = $operator->getValues();
                $bindKey = $this->registerOperatorBind($binds, $values[0] ?? 0);

                return "{$quotedColumn} = datetime({$quotedColumn}, '-' || abs(:$bindKey) || ' days')";

            case Operator::TYPE_DATE_SET_NOW:
                return "{$quotedColumn} = datetime('now')";

            default:
                // Fall back to parent implementation for other operators
                return parent::getOperatorSQL($column, $operator, $binds);
        }
    }

    /**
     * Override getUpsertStatement to use SQLite's ON CONFLICT syntax instead of MariaDB's ON DUPLICATE KEY UPDATE
     *
     * @param string $tableName
     * @param string $columns
     * @param array<string> $batchKeys
     * @param array<string> $attributes
     * @param array<mixed> $bindValues
     * @param string $attribute
     * @param array<string, Operator> $operators
     * @return mixed
     */
    public function getUpsertStatement(
        string $tableName,
        string $columns,
        array $batchKeys,
        array $attributes,
        array $bindValues,
        string $attribute = '',
        array $operators = [],
    ): mixed {
        $getUpdateClause = function (string $attribute, bool $increment = false): string {
            $attribute = $this->quote($this->filter($attribute));
            if ($increment) {
                $new = "{$attribute} + excluded.{$attribute}";
            } else {
                $new = "excluded.{$attribute}";
            }

            if ($this->sharedTables) {
                return "{$attribute} = CASE WHEN _tenant = excluded._tenant THEN {$new} ELSE {$attribute} END";
            }

            return "{$attribute} = {$new}";
        };

        $updateColumns = [];
        $operatorBinds = [];

        if (!empty($attribute)) {
            // Increment specific column by its new value in place
            $updateColumns = [
                $getUpdateClause($attribute, increment: true),
                $getUpdateClause('_updatedAt'),
            ];
        } else {
            // Update all columns, handling operators separately
            foreach (\array_keys($attributes) as $attr) {
                /**
                 * @var string $attr
                 */
                $filteredAttr = $this->filter($attr);

                // Check if this attribute has an operator
                if (isset($operators[$attr])) {
                    $operatorSQL = $this->getOperatorSQL($filteredAttr, $operators[$attr], $operatorBinds);
                    if ($operatorSQL !== null) {
                        $updateColumns[] = $operatorSQL;
                    }
                } else {
                    if (!in_array($attr, ['_uid', '_id', '_createdAt', '_tenant'])) {
                        $updateColumns[] = $getUpdateClause($filteredAttr);
                    }
                }
            }
        }

        // getSQLIndex prepends `_tenant` to every index column list
        // under shared tables, so the actual UNIQUE on the documents
        // table is (_tenant, _uid). SQLite's ON CONFLICT clause needs
        // the same column order to match a UNIQUE constraint.
        $conflictKeys = $this->sharedTables ? '(_tenant, _uid)' : '(_uid)';

        $stmt = $this->getPDO()->prepare(
            "
            INSERT INTO {$this->getSQLTable($tableName)} {$columns}
            VALUES " . \implode(', ', $batchKeys) . "
            ON CONFLICT {$conflictKeys} DO UPDATE
                SET " . \implode(', ', $updateColumns)
        );

        // Bind regular attribute values
        foreach ($bindValues as $key => $binding) {
            $stmt->bindValue($key, $binding, $this->getPDOType($binding));
        }

        foreach ($operatorBinds as $bindKey => $bindValue) {
            $stmt->bindValue($bindKey, $bindValue, $this->getPDOType($bindValue));
        }

        return $stmt;
    }

    public function getSupportForAlterLocks(): bool
    {
        return false;
    }

    public function getSupportNonUtfCharacters(): bool
    {
        return false;
    }

    /**
     * Is PCRE regex supported?
     * SQLite does not have native REGEXP support - it requires compile-time option or user-defined function
     *
     * @return bool
     */
    public function getSupportForPCRERegex(): bool
    {
        return $this->pcreRegistered;
    }

    /**
     * Is POSIX regex supported?
     * SQLite does not have native REGEXP support - it requires compile-time option or user-defined function
     *
     * @return bool
     */
    public function getSupportForPOSIXRegex(): bool
    {
        // The PHP-implemented REGEXP UDF runs preg_match (PCRE), not POSIX.
        return false;
    }

    public function getSupportForTTLIndexes(): bool
    {
        return false;
    }

    protected function getInsertKeyword(): string
    {
        return $this->skipDuplicates ? 'INSERT OR IGNORE INTO' : 'INSERT INTO';
    }

    /**
     * SQLite's ALTER TABLE accepts a single column per statement, so the
     * shared SQL implementation that joins many ADD COLUMN clauses with
     * commas doesn't parse here. Loop over createAttribute instead.
     *
     * @param array<array<string, mixed>> $attributes
     */
    public function createAttributes(string $collection, array $attributes): bool
    {
        // The flag advertises atomic batch creation. SQLite has no
        // multi-column ADD, but DDL inside a transaction is still
        // rolled back on failure, so a mid-batch error doesn't leave
        // the table half-extended.
        $this->startTransaction();
        try {
            foreach ($attributes as $attribute) {
                $this->createAttribute(
                    $collection,
                    $attribute['$id'],
                    $attribute['type'],
                    $attribute['size'] ?? 0,
                    $attribute['signed'] ?? true,
                    $attribute['array'] ?? false,
                    $attribute['required'] ?? false,
                );
            }
            $this->commitTransaction();
        } catch (\Throwable $e) {
            try {
                $this->rollbackTransaction();
            } catch (\Throwable) {
            }
            throw $e;
        }

        return true;
    }

    /**
     * MariaDB::createRelationship concatenates multiple ALTER TABLE
     * statements with `;` and runs them through a single prepare/execute,
     * which only works because MySQL accepts multi-statement queries.
     * SQLite's PDO driver runs the first statement and silently drops the
     * rest, so re-implement the dispatch with one statement per call.
     */
    public function createRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay = false,
        string $id = '',
        string $twoWayKey = ''
    ): bool {
        $name = $this->filter($collection);
        $relatedName = $this->filter($relatedCollection);
        $table = $this->getSQLTable($name);
        $relatedTable = $this->getSQLTable($relatedName);
        $id = $this->filter($id);
        $twoWayKey = $this->filter($twoWayKey);
        $sqlType = $this->getSQLType(Database::VAR_RELATIONSHIP, 0, false, false, false);

        $statements = match ($type) {
            Database::RELATION_ONE_TO_ONE => $twoWay
                ? [
                    "ALTER TABLE {$table} ADD COLUMN `{$id}` {$sqlType} DEFAULT NULL",
                    "ALTER TABLE {$relatedTable} ADD COLUMN `{$twoWayKey}` {$sqlType} DEFAULT NULL",
                ]
                : ["ALTER TABLE {$table} ADD COLUMN `{$id}` {$sqlType} DEFAULT NULL"],
            Database::RELATION_ONE_TO_MANY => ["ALTER TABLE {$relatedTable} ADD COLUMN `{$twoWayKey}` {$sqlType} DEFAULT NULL"],
            Database::RELATION_MANY_TO_ONE => ["ALTER TABLE {$table} ADD COLUMN `{$id}` {$sqlType} DEFAULT NULL"],
            Database::RELATION_MANY_TO_MANY => [],
            default => throw new DatabaseException('Invalid relationship type'),
        };

        foreach ($statements as $stmt) {
            $stmt = $this->trigger(Database::EVENT_ATTRIBUTE_CREATE, $stmt);
            $this->getPDO()->prepare($stmt)->execute();
        }

        return true;
    }

    public function updateRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay,
        string $key,
        string $twoWayKey,
        string $side,
        ?string $newKey = null,
        ?string $newTwoWayKey = null,
    ): bool {
        $name = $this->filter($collection);
        $relatedName = $this->filter($relatedCollection);
        $table = $this->getSQLTable($name);
        $relatedTable = $this->getSQLTable($relatedName);
        $key = $this->filter($key);
        $twoWayKey = $this->filter($twoWayKey);

        if (!\is_null($newKey)) {
            $newKey = $this->filter($newKey);
        }
        if (!\is_null($newTwoWayKey)) {
            $newTwoWayKey = $this->filter($newTwoWayKey);
        }

        $statements = [];

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if (!\is_null($newKey) && $key !== $newKey) {
                    $statements[] = "ALTER TABLE {$table} RENAME COLUMN `{$key}` TO `{$newKey}`";
                }
                if ($twoWay && !\is_null($newTwoWayKey) && $twoWayKey !== $newTwoWayKey) {
                    $statements[] = "ALTER TABLE {$relatedTable} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`";
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    if (!\is_null($newTwoWayKey) && $twoWayKey !== $newTwoWayKey) {
                        $statements[] = "ALTER TABLE {$relatedTable} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`";
                    }
                } else {
                    if (!\is_null($newKey) && $key !== $newKey) {
                        $statements[] = "ALTER TABLE {$table} RENAME COLUMN `{$key}` TO `{$newKey}`";
                    }
                }
                break;
            case Database::RELATION_MANY_TO_ONE:
                if ($side === Database::RELATION_SIDE_CHILD) {
                    if (!\is_null($newTwoWayKey) && $twoWayKey !== $newTwoWayKey) {
                        $statements[] = "ALTER TABLE {$relatedTable} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`";
                    }
                } else {
                    if (!\is_null($newKey) && $key !== $newKey) {
                        $statements[] = "ALTER TABLE {$table} RENAME COLUMN `{$key}` TO `{$newKey}`";
                    }
                }
                break;
            case Database::RELATION_MANY_TO_MANY:
                $metadataCollection = new Document(['$id' => Database::METADATA]);
                $collection = $this->getDocument($metadataCollection, $collection);
                $relatedCollection = $this->getDocument($metadataCollection, $relatedCollection);

                $junction = $this->getSQLTable('_' . $collection->getSequence() . '_' . $relatedCollection->getSequence());

                if (!\is_null($newKey)) {
                    $statements[] = "ALTER TABLE {$junction} RENAME COLUMN `{$key}` TO `{$newKey}`";
                }
                if ($twoWay && !\is_null($newTwoWayKey)) {
                    $statements[] = "ALTER TABLE {$junction} RENAME COLUMN `{$twoWayKey}` TO `{$newTwoWayKey}`";
                }
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        foreach ($statements as $stmt) {
            $stmt = $this->trigger(Database::EVENT_ATTRIBUTE_UPDATE, $stmt);
            $this->getPDO()->prepare($stmt)->execute();
        }

        return true;
    }

    public function deleteRelationship(
        string $collection,
        string $relatedCollection,
        string $type,
        bool $twoWay,
        string $key,
        string $twoWayKey,
        string $side
    ): bool {
        $name = $this->filter($collection);
        $relatedName = $this->filter($relatedCollection);
        $table = $this->getSQLTable($name);
        $relatedTable = $this->getSQLTable($relatedName);
        $key = $this->filter($key);
        $twoWayKey = $this->filter($twoWayKey);

        $statements = [];

        switch ($type) {
            case Database::RELATION_ONE_TO_ONE:
                if ($side === Database::RELATION_SIDE_PARENT) {
                    $statements[] = "ALTER TABLE {$table} DROP COLUMN `{$key}`";
                    if ($twoWay) {
                        $statements[] = "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`";
                    }
                } elseif ($side === Database::RELATION_SIDE_CHILD) {
                    $statements[] = "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`";
                    if ($twoWay) {
                        $statements[] = "ALTER TABLE {$table} DROP COLUMN `{$key}`";
                    }
                }
                break;
            case Database::RELATION_ONE_TO_MANY:
                $statements[] = $side === Database::RELATION_SIDE_PARENT
                    ? "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`"
                    : "ALTER TABLE {$table} DROP COLUMN `{$key}`";
                break;
            case Database::RELATION_MANY_TO_ONE:
                $statements[] = $side === Database::RELATION_SIDE_PARENT
                    ? "ALTER TABLE {$table} DROP COLUMN `{$key}`"
                    : "ALTER TABLE {$relatedTable} DROP COLUMN `{$twoWayKey}`";
                break;
            case Database::RELATION_MANY_TO_MANY:
                $metadataCollection = new Document(['$id' => Database::METADATA]);
                $collection = $this->getDocument($metadataCollection, $collection);
                $relatedCollection = $this->getDocument($metadataCollection, $relatedCollection);

                $junctionBase = $side === Database::RELATION_SIDE_PARENT
                    ? '_' . $collection->getSequence() . '_' . $relatedCollection->getSequence()
                    : '_' . $relatedCollection->getSequence() . '_' . $collection->getSequence();

                $statements[] = "DROP TABLE {$this->getSQLTable($junctionBase)}";
                $statements[] = "DROP TABLE {$this->getSQLTable($junctionBase . '_perms')}";
                break;
            default:
                throw new DatabaseException('Invalid relationship type');
        }

        foreach ($statements as $stmt) {
            $stmt = $this->trigger(Database::EVENT_ATTRIBUTE_DELETE, $stmt);
            $this->getPDO()->prepare($stmt)->execute();
        }

        return true;
    }

    /**
     * Introspect a collection's columns via PRAGMA table_info instead of
     * MariaDB's INFORMATION_SCHEMA.COLUMNS, which doesn't exist in SQLite.
     * Returned shape matches the MariaDB result enough that
     * Database::analyzeCollection() doesn't have to special-case the
     * adapter.
     *
     * @return array<Document>
     */
    public function getSchemaAttributes(string $collection): array
    {
        $table = "{$this->getNamespace()}_{$this->filter($collection)}";

        $stmt = $this->getPDO()->prepare("PRAGMA table_info(`{$table}`)");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $stmt->closeCursor();

        $results = [];
        foreach ($rows as $row) {
            $rawType = (string) ($row['type'] ?? '');
            $parsed = $this->parseSqliteColumnType($rawType);

            $results[] = new Document([
                '$id' => $row['name'],
                'columnDefault' => $row['dflt_value'] ?? null,
                'isNullable' => empty($row['notnull']) ? 'YES' : 'NO',
                'dataType' => $parsed['dataType'],
                'characterMaximumLength' => $parsed['characterMaximumLength'],
                'numericPrecision' => $parsed['numericPrecision'],
                'numericScale' => $parsed['numericScale'],
                'datetimePrecision' => $parsed['datetimePrecision'],
                'columnType' => \strtolower($rawType),
                'columnKey' => !empty($row['pk']) ? 'PRI' : '',
                'extra' => '',
            ]);
        }

        return $results;
    }

    /**
     * Introspect a collection's indexes via PRAGMA index_list +
     * PRAGMA index_info. Returns one Document per index with a `columns`
     * array, matching the grouped shape MariaDB::getSchemaIndexes returns
     * so Database::createIndex can compare `columns` against the requested
     * attributes without special-casing the adapter.
     *
     * @return array<Document>
     */
    public function getSchemaIndexes(string $collection): array
    {
        $table = "{$this->getNamespace()}_{$this->filter($collection)}";

        $stmt = $this->getPDO()->prepare("PRAGMA index_list(`{$table}`)");
        $stmt->execute();
        $indexes = $stmt->fetchAll();
        $stmt->closeCursor();

        $results = [];
        foreach ($indexes as $index) {
            $name = $index['name'];
            $unique = !empty($index['unique']);

            $colStmt = $this->getPDO()->prepare("PRAGMA index_info(`{$name}`)");
            $colStmt->execute();
            $cols = $colStmt->fetchAll();
            $colStmt->closeCursor();

            \usort($cols, fn ($a, $b) => ((int) $a['seqno']) <=> ((int) $b['seqno']));

            $columns = [];
            $lengths = [];
            foreach ($cols as $col) {
                $columns[] = $col['name'];
                $lengths[] = null;
            }

            $results[] = new Document([
                '$id' => $name,
                'indexName' => $name,
                'indexType' => 'BTREE',
                'nonUnique' => $unique ? 0 : 1,
                'columns' => $columns,
                'lengths' => $lengths,
            ]);
        }

        // PRAGMA index_list misses FTS5 vtables.
        foreach ($this->getFulltextSchemaIndexes($collection) as $entry) {
            $results[] = new Document($entry);
        }

        return $results;
    }

    /**
     * Schema-index entries for FTS5 fulltext tables on `$collection`.
     * Maps each back to a metadata index id when possible.
     *
     * Each entry has keys:
     * - `$id`: string
     * - `indexName`: string
     * - `indexType`: string
     * - `nonUnique`: int
     * - `columns`: array<string>
     * - `lengths`: array<null>
     *
     * @return array<array<string, mixed>>
     */
    protected function getFulltextSchemaIndexes(string $collection): array
    {
        $tables = $this->findFulltextTables($collection);

        if (empty($tables)) {
            return [];
        }

        $hashToId = [];
        try {
            $metadataCollection = new Document(['$id' => Database::METADATA]);
            $collectionDoc = $this->getDocument($metadataCollection, $collection);
            if (!$collectionDoc->isEmpty()) {
                foreach ($collectionDoc->getAttribute('indexes', []) as $index) {
                    $indexId = $index instanceof Document
                        ? $index->getId()
                        : (\is_array($index) ? ($index['$id'] ?? null) : null);
                    $type = $index instanceof Document
                        ? $index->getAttribute('type')
                        : (\is_array($index) ? ($index['type'] ?? null) : null);

                    if ($indexId === null || $type !== Database::INDEX_FULLTEXT) {
                        continue;
                    }

                    $attributes = $index instanceof Document
                        ? $index->getAttribute('attributes', [])
                        : ($index['attributes'] ?? []);

                    $internal = \array_map(
                        fn (string $a) => $this->getInternalKeyForAttribute($a),
                        (array) $attributes
                    );
                    $hashToId[$this->getFulltextTableName($collection, $internal)] = $this->filter((string) $indexId);
                }
            }
        } catch (\Throwable) {
        }

        $entries = [];
        foreach ($tables as $ftsTable) {
            $info = $this->getPDO()->prepare("PRAGMA table_info(`{$ftsTable}`)");
            $info->execute();
            $cols = $info->fetchAll(PDO::FETCH_ASSOC);
            $info->closeCursor();

            $columns = [];
            foreach ($cols as $col) {
                $name = (string) ($col['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $columns[] = $name;
            }

            $id = $hashToId[$ftsTable] ?? $ftsTable;

            $entries[] = [
                '$id' => $id,
                'indexName' => $id,
                'indexType' => 'FULLTEXT',
                'nonUnique' => 1,
                'columns' => $columns,
                'lengths' => \array_fill(0, \count($columns), null),
            ];
        }

        return $entries;
    }

    /**
     * Parse a SQLite type declaration like `VARCHAR(36)` into the column-info
     * shape exposed by getSchemaAttributes. Mirrors what MariaDB returns from
     * INFORMATION_SCHEMA.COLUMNS so callers don't have to special-case the
     * adapter — TEXT family types report their MariaDB byte ceilings,
     * VARCHAR/CHAR thread the parenthesised size into characterMaximumLength,
     * DATETIME's parenthesised value routes to datetimePrecision, and
     * integer types fall back to MariaDB's default precision values.
     *
     * @return array{
     *     dataType: string,
     *     characterMaximumLength: ?string,
     *     numericPrecision: ?string,
     *     numericScale: ?string,
     *     datetimePrecision: ?string,
     * }
     */
    private function parseSqliteColumnType(string $declaration): array
    {
        $declaration = \trim(\preg_replace('/\s+/', ' ', $declaration) ?? '');

        $base = $declaration;
        $argument = null;
        $secondArgument = null;
        if (\preg_match('/^([A-Za-z]+)\s*\((\d+)(?:\s*,\s*(\d+))?\s*\)/', $declaration, $matches) === 1) {
            $base = $matches[1];
            $argument = (int) $matches[2];
            if (isset($matches[3]) && $matches[3] !== '') {
                $secondArgument = (int) $matches[3];
            }
        }

        $dataType = \strtolower($base);
        // SQLite spells INT and INTEGER interchangeably for declared types.
        // Under emulation, canonicalise to MariaDB's reported `int` so
        // getSchemaAttributes matches that adapter's contract; otherwise
        // keep the verbatim form the user declared.
        if ($this->emulateMySQL && $dataType === 'integer') {
            $dataType = 'int';
        }

        $result = [
            'dataType' => $dataType,
            'characterMaximumLength' => null,
            'numericPrecision' => null,
            'numericScale' => null,
            'datetimePrecision' => null,
        ];

        // VARCHAR / CHAR / DATETIME(n) / DECIMAL(p,s) length+precision
        // come straight from the declaration — that's true for vanilla
        // SQLite too. The MariaDB byte ceilings (TEXT/MEDIUMTEXT/etc.)
        // and the integer/float precision defaults are MariaDB-specific
        // INFORMATION_SCHEMA conventions, so report them only under
        // emulation.
        switch ($dataType) {
            case 'varchar':
            case 'char':
                if ($argument !== null) {
                    $result['characterMaximumLength'] = (string) $argument;
                }
                break;

            case 'datetime':
            case 'timestamp':
            case 'time':
                if ($argument !== null) {
                    $result['datetimePrecision'] = (string) $argument;
                }
                break;

            case 'decimal':
            case 'numeric':
                if ($argument !== null) {
                    $result['numericPrecision'] = (string) $argument;
                }
                if ($secondArgument !== null) {
                    $result['numericScale'] = (string) $secondArgument;
                } elseif ($this->emulateMySQL && $argument !== null) {
                    $result['numericScale'] = '0';
                }
                break;
        }

        /**
         * MariaDB byte ceilings for TEXT-family types, mirrored so PRAGMA-based
         * introspection produces the same characterMaximumLength values that
         * INFORMATION_SCHEMA.COLUMNS would on MariaDB.
         */

        if ($this->emulateMySQL) {
            switch ($dataType) {
                case 'text':
                    $result['characterMaximumLength'] = '' .  Database::MAX_TEXT_BYTES;
                    break;

                case 'mediumtext':
                    $result['characterMaximumLength'] = '' . Database::MAX_MEDIUMTEXT_BYTES;
                    break;

                case 'longtext':
                case 'json':
                    $result['characterMaximumLength'] = '' .  Database::MAX_LONGTEXT_BYTES;
                    break;

                case 'tinyint':
                    $result['numericPrecision'] = '3';
                    break;

                case 'smallint':
                    $result['numericPrecision'] = '5';
                    break;

                case 'mediumint':
                    $result['numericPrecision'] = '7';
                    break;

                case 'int':
                case 'integer':
                    $result['numericPrecision'] = '10';
                    break;

                case 'bigint':
                    $result['numericPrecision'] = '19';
                    break;

                case 'decimal':
                case 'numeric':
                    if ($result['numericPrecision'] === null) {
                        $result['numericPrecision'] = '10';
                    }
                    break;

                case 'float':
                    $result['numericPrecision'] = '12';
                    break;

                case 'double':
                    $result['numericPrecision'] = '22';
                    break;
            }
        }

        return $result;
    }

    /**
     * SQLite has no MATCH ... AGAINST. Route SEARCH/NOT_SEARCH through the
     * collection's FTS5 virtual table; for LIKE-using comparisons append
     * an explicit ESCAPE clause because SQLite — unlike MariaDB — does
     * not honour `\` as a default escape and the inherited
     * escapeWildcards() emits backslash escapes on every wildcard.
     * Everything else falls through to the MariaDB implementation.
     */
    protected function getSQLCondition(Query $query, array &$binds, ?string $forCollection = null): string
    {
        $method = $query->getMethod();

        $likeMethods = [
            Query::TYPE_STARTS_WITH,
            Query::TYPE_NOT_STARTS_WITH,
            Query::TYPE_ENDS_WITH,
            Query::TYPE_NOT_ENDS_WITH,
            Query::TYPE_CONTAINS,
            Query::TYPE_CONTAINS_ANY,
            Query::TYPE_NOT_CONTAINS,
        ];

        if (\in_array($method, $likeMethods, true)) {
            // Array CONTAINS via json_each — exact element match without
            // LIKE substring false positives (`%2%` matching `[12, 200]`).
            $arrayContainsMethods = [
                Query::TYPE_CONTAINS,
                Query::TYPE_CONTAINS_ANY,
                Query::TYPE_NOT_CONTAINS,
            ];
            if ($query->onArray() && \in_array($method, $arrayContainsMethods, true)) {
                return $this->buildArrayContainsCondition($query, $binds);
            }

            return $this->getLikeCondition($query, $binds);
        }

        if ($method !== Query::TYPE_SEARCH && $method !== Query::TYPE_NOT_SEARCH) {
            return parent::getSQLCondition($query, $binds, $forCollection);
        }

        $query->setAttribute($this->getInternalKeyForAttribute($query->getAttribute()));
        $attribute = $this->filter($query->getAttribute());
        $alias = $this->quote(Query::DEFAULT_ALIAS);
        $placeholder = ID::unique();

        $rawValue = (string) $query->getValue();
        $ftsValue = $this->getFTS5Value($rawValue);

        if ($ftsValue === '') {
            // Empty term — FTS5 syntax-errors on the empty string.
            return $method === Query::TYPE_SEARCH ? '1 = 0' : '1 = 1';
        }

        $ftsTable = $forCollection === null
            ? null
            : $this->findFulltextTableForAttribute($forCollection, $attribute);

        if ($ftsTable === null) {
            // LIKE on the raw value — the FTS5-formatted form embeds
            // `OR`/`*` that LIKE would treat as literal.
            return $this->buildSearchLikeFallback($attribute, $rawValue, $alias, $placeholder, $method, $binds);
        }

        $binds[":{$placeholder}_0"] = $ftsValue;

        $subquery = "{$alias}.`_id` IN (SELECT rowid FROM `{$ftsTable}` WHERE `{$ftsTable}` MATCH :{$placeholder}_0)";

        return $method === Query::TYPE_SEARCH ? $subquery : "NOT ({$subquery})";
    }

    /**
     * SEARCH fallback to LIKE when no FTS5 table covers the attribute.
     *
     * @param array<string,mixed> $binds
     */
    private function buildSearchLikeFallback(
        string $attribute,
        string $value,
        string $alias,
        string $placeholder,
        string $method,
        array &$binds,
    ): string {
        $binds[":{$placeholder}_0"] = '%' . $this->escapeWildcards($value) . '%';
        $sql = "{$alias}.{$this->quote($attribute)} LIKE :{$placeholder}_0 ESCAPE '\\'";

        return $method === Query::TYPE_SEARCH ? $sql : "NOT ({$sql})";
    }

    /**
     * Array CONTAINS / CONTAINS_ANY / NOT_CONTAINS via json_each. Exact
     * element match — avoids the LIKE substring false positives where
     * `%2%` matches `[12, 200]` and `%"apple"%` matches `["pineapple"]`.
     *
     * @param array<string,mixed> $binds
     */
    private function buildArrayContainsCondition(Query $query, array &$binds): string
    {
        $method = $query->getMethod();
        $query->setAttribute($this->getInternalKeyForAttribute($query->getAttribute()));

        $attribute = $this->quote($this->filter($query->getAttribute()));
        $alias = $this->quote(Query::DEFAULT_ALIAS);
        $placeholder = ID::unique();

        $values = $query->getValues();
        if (empty($values)) {
            return '';
        }

        $params = [];
        foreach ($values as $key => $value) {
            $param = ":{$placeholder}_{$key}";
            $binds[$param] = $value;
            $params[] = $param;
        }

        $expression = "EXISTS (SELECT 1 FROM json_each({$alias}.{$attribute}) WHERE value IN ("
            . \implode(', ', $params)
            . '))';

        return $method === Query::TYPE_NOT_CONTAINS ? "NOT {$expression}" : $expression;
    }

    /**
     * FTS5 vtable on `$collection` that covers `$attribute`. Multi-column
     * indexes can't be addressed from a single attribute alone — the
     * lookup is via the cached attribute → table map.
     */
    protected function findFulltextTableForAttribute(string $collection, string $attribute): ?string
    {
        if (!\array_key_exists($collection, $this->ftsTableCache)) {
            $this->ftsTableCache[$collection] = $this->buildFulltextAttributeMap($collection);
        }

        return $this->ftsTableCache[$collection][$attribute] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function buildFulltextAttributeMap(string $collection): array
    {
        $map = [];
        foreach ($this->findFulltextTables($collection) as $table) {
            $info = $this->getPDO()->prepare("PRAGMA table_info(`{$table}`)");
            $info->execute();
            $cols = $info->fetchAll(PDO::FETCH_ASSOC);
            $info->closeCursor();
            foreach ($cols as $col) {
                $name = $col['name'] ?? null;
                if (\is_string($name) && $name !== '') {
                    $map[$name] = $table;
                }
            }
        }

        return $map;
    }

    /**
     * Compile STARTS_WITH / ENDS_WITH / CONTAINS (and NOT variants) into
     * LIKE with an explicit ESCAPE clause — SQLite needs it to honour
     * the backslash escapes escapeWildcards() inserts.
     *
     * @param array<string,mixed> $binds
     */
    protected function getLikeCondition(Query $query, array &$binds): string
    {
        $method = $query->getMethod();
        $query->setAttribute($this->getInternalKeyForAttribute($query->getAttribute()));

        $attribute = $this->quote($this->filter($query->getAttribute()));
        $alias = $this->quote(Query::DEFAULT_ALIAS);
        $placeholder = ID::unique();

        $isNotQuery = \in_array($method, [
            Query::TYPE_NOT_STARTS_WITH,
            Query::TYPE_NOT_ENDS_WITH,
            Query::TYPE_NOT_CONTAINS,
        ], true);

        $conditions = [];
        foreach ($query->getValues() as $key => $value) {
            $bound = match ($method) {
                Query::TYPE_STARTS_WITH, Query::TYPE_NOT_STARTS_WITH => $this->escapeWildcards($value) . '%',
                Query::TYPE_ENDS_WITH, Query::TYPE_NOT_ENDS_WITH => '%' . $this->escapeWildcards($value),
                Query::TYPE_CONTAINS, Query::TYPE_CONTAINS_ANY, Query::TYPE_NOT_CONTAINS => '%' . $this->escapeWildcards($value) . '%',
                default => $value,
            };

            $binds[":{$placeholder}_{$key}"] = $bound;
            $operator = $isNotQuery ? 'NOT LIKE' : 'LIKE';
            $conditions[] = "{$alias}.{$attribute} {$operator} :{$placeholder}_{$key} ESCAPE '\\'";
        }

        $separator = $isNotQuery ? ' AND ' : ' OR ';

        return empty($conditions) ? '' : '(' . \implode($separator, $conditions) . ')';
    }

    /**
     * Format a SEARCH term as MariaDB BOOLEAN MODE: OR-joined tokens with
     * the trailing token prefix-matched. Empty when no token survives.
     */
    protected function getFTS5Value(string $value): string
    {
        // Balanced wrapping `"..."` triggers exact-phrase mode.
        $exact = \strlen($value) >= 2
            && \str_starts_with($value, '"')
            && \str_ends_with($value, '"')
            && \substr_count($value, '"') === 2;

        // Strip FTS5 syntax characters so multi-word terms split.
        $sanitized = \preg_replace('/[^\p{L}\p{N}_\s]+/u', ' ', $value) ?? '';
        $sanitized = \trim((string) \preg_replace('/\s+/', ' ', $sanitized));

        if ($sanitized === '') {
            return '';
        }

        if ($exact) {
            return '"' . $sanitized . '"';
        }

        $tokens = \explode(' ', $sanitized);
        // Quote AND/OR/NOT/NEAR — bare, FTS5 treats them as operators.
        $tokens = \array_map(static function (string $token): string {
            if (\preg_match('/^(AND|OR|NOT|NEAR)$/i', $token) === 1) {
                return '"' . $token . '"';
            }
            return $token;
        }, $tokens);
        $last = \array_pop($tokens);
        if ($last !== null && !\str_starts_with($last, '"')) {
            $last .= '*';
        }
        $tokens[] = $last;

        return \implode(' OR ', $tokens);
    }
}
